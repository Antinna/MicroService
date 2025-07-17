<?php

namespace Antinna\Auth\Services;

use Antinna\Auth\Config\Environment;
use Antinna\Auth\Repositories\UserRepository;
use Antinna\Auth\Services\AuditLogger;
use Antinna\Auth\Database\Connection;
use Webauthn\Server;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialSourceRepository;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\UserVerificationRequirement;
use Webauthn\AuthenticatorAttachment;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\Util\CoseAlgorithmIdentifier;
use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA;
use Cose\Algorithm\Signature\EdDSA;
use Cose\Algorithm\Signature\RSA;
use PDO;
use Exception;

/**
 * WebAuthn Passkey Handler
 */
class PasskeyHandler implements PublicKeyCredentialSourceRepository
{
    private PDO $db;
    private UserRepository $userRepository;
    private AuditLogger $auditLogger;
    private Server $webauthnServer;

    public function __construct()
    {
        $this->db = Connection::getInstance()->getConnection();
        $this->userRepository = new UserRepository();
        $this->auditLogger = new AuditLogger();
        $this->initializeWebAuthnServer();
    }

    /**
     * Initialize WebAuthn server
     */
    private function initializeWebAuthnServer(): void
    {
        // Create relying party entity
        Environment::load();
        $rpEntity = PublicKeyCredentialRpEntity::create(
            'Auth Service',
            Environment::get('APP_DOMAIN', 'localhost'),
            null // icon URL (optional)
        );

        // Create algorithm manager
        $algorithmManager = Manager::create()
            ->add(ECDSA\ES256::create())
            ->add(ECDSA\ES384::create())
            ->add(ECDSA\ES512::create())
            ->add(EdDSA\Ed25519::create())
            ->add(RSA\RS256::create())
            ->add(RSA\RS384::create())
            ->add(RSA\RS512::create())
            ->add(RSA\PS256::create())
            ->add(RSA\PS384::create())
            ->add(RSA\PS512::create());

        // Create WebAuthn server
        $this->webauthnServer = Server::create(
            $rpEntity,
            $this, // This class implements PublicKeyCredentialSourceRepository
            null, // Token binding handler (optional)
            $algorithmManager
        );
    }

    /**
     * Generate registration options for passkey
     */
    public function generateRegistrationOptions(int $userId, string $deviceName = 'Default Device'): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        try {
            // Create user entity
            $userEntity = PublicKeyCredentialUserEntity::create(
                $user['email'],
                (string)$user['id'],
                $user['email'],
                null // icon URL (optional)
            );

            // Create authenticator selection criteria
            $authenticatorSelection = AuthenticatorSelectionCriteria::create()
                ->setAuthenticatorAttachment(AuthenticatorAttachment::PLATFORM)
                ->setUserVerification(UserVerificationRequirement::PREFERRED)
                ->setResidentKey(true);

            // Create public key credential parameters
            $publicKeyCredentialParametersList = [
                PublicKeyCredentialParameters::create('public-key', CoseAlgorithmIdentifier::ECDSA_P256_SHA256),
                PublicKeyCredentialParameters::create('public-key', CoseAlgorithmIdentifier::ECDSA_P384_SHA384),
                PublicKeyCredentialParameters::create('public-key', CoseAlgorithmIdentifier::ECDSA_P521_SHA512),
                PublicKeyCredentialParameters::create('public-key', CoseAlgorithmIdentifier::RSASSA_PSS_SHA256),
                PublicKeyCredentialParameters::create('public-key', CoseAlgorithmIdentifier::RSASSA_PSS_SHA384),
                PublicKeyCredentialParameters::create('public-key', CoseAlgorithmIdentifier::RSASSA_PSS_SHA512),
            ];

            // Get existing credentials to exclude
            $excludeCredentials = $this->getExistingCredentials($userId);

            // Generate creation options
            $creationOptions = $this->webauthnServer->generatePublicKeyCredentialCreationOptions(
                $userEntity,
                PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
                $publicKeyCredentialParametersList,
                $authenticatorSelection,
                $excludeCredentials
            );

            // Store challenge temporarily
            $this->storeChallenge($userId, $creationOptions->getChallenge(), 'registration', $deviceName);

            $this->auditLogger->log('passkey_registration_initiated', 'Passkey registration initiated', $userId);

            return [
                'success' => true,
                'options' => [
                    'challenge' => base64url_encode($creationOptions->getChallenge()),
                    'rp' => [
                        'name' => $creationOptions->getRp()->getName(),
                        'id' => $creationOptions->getRp()->getId()
                    ],
                    'user' => [
                        'id' => base64url_encode($creationOptions->getUser()->getId()),
                        'name' => $creationOptions->getUser()->getName(),
                        'displayName' => $creationOptions->getUser()->getDisplayName()
                    ],
                    'pubKeyCredParams' => array_map(function($param) {
                        return [
                            'type' => $param->getType(),
                            'alg' => $param->getAlg()
                        ];
                    }, $creationOptions->getPubKeyCredParams()),
                    'authenticatorSelection' => [
                        'authenticatorAttachment' => $creationOptions->getAuthenticatorSelection()?->getAuthenticatorAttachment(),
                        'userVerification' => $creationOptions->getAuthenticatorSelection()?->getUserVerification(),
                        'requireResidentKey' => $creationOptions->getAuthenticatorSelection()?->isResidentKey()
                    ],
                    'timeout' => $creationOptions->getTimeout(),
                    'attestation' => $creationOptions->getAttestation()
                ],
                'device_name' => $deviceName
            ];

        } catch (Exception $e) {
            $this->auditLogger->log('passkey_registration_error', 'Passkey registration error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            
            return [
                'success' => false,
                'error' => 'Failed to generate registration options',
                'code' => 'REGISTRATION_OPTIONS_ERROR'
            ];
        }
    }

    /**
     * Verify registration response and create passkey
     */
    public function verifyRegistration(int $userId, array $response): array
    {
        $user = $this->userRepository->find($userId);
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ];
        }

        try {
            // Get stored challenge
            $challengeData = $this->getStoredChallenge($userId, 'registration');
            if (!$challengeData) {
                return [
                    'success' => false,
                    'error' => 'Registration challenge not found or expired',
                    'code' => 'CHALLENGE_NOT_FOUND'
                ];
            }

            // Verify the registration response
            $publicKeyCredentialSource = $this->webauthnServer->loadAndCheckAttestationResponse(
                json_encode($response),
                $challengeData['challenge'],
                $_SERVER['HTTP_HOST'] ?? 'localhost'
            );

            // Store the credential
            $passkeyId = $this->storePasskey($userId, $publicKeyCredentialSource, $challengeData['device_name']);

            if ($passkeyId) {
                // Clear the challenge
                $this->clearChallenge($userId, 'registration');

                $this->auditLogger->log('passkey_registered', 'Passkey registered successfully', $userId);

                return [
                    'success' => true,
                    'passkey_id' => $passkeyId,
                    'device_name' => $challengeData['device_name'],
                    'message' => 'Passkey registered successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to store passkey',
                    'code' => 'PASSKEY_STORAGE_ERROR'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->log('passkey_registration_verification_error', 'Passkey registration verification error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            
            return [
                'success' => false,
                'error' => 'Registration verification failed',
                'code' => 'REGISTRATION_VERIFICATION_ERROR'
            ];
        }
    }

    /**
     * Generate authentication options for passkey
     */
    public function generateAuthenticationOptions(?int $userId = null): array
    {
        try {
            // Get allowed credentials (if user is specified)
            $allowedCredentials = [];
            if ($userId) {
                $allowedCredentials = $this->getExistingCredentials($userId);
            }

            // Generate request options
            $requestOptions = $this->webauthnServer->generatePublicKeyCredentialRequestOptions(
                UserVerificationRequirement::PREFERRED,
                $allowedCredentials
            );

            // Store challenge temporarily
            $challengeId = $this->storeChallenge($userId, $requestOptions->getChallenge(), 'authentication');

            $this->auditLogger->log('passkey_auth_initiated', 'Passkey authentication initiated', $userId);

            return [
                'success' => true,
                'options' => [
                    'challenge' => base64url_encode($requestOptions->getChallenge()),
                    'timeout' => $requestOptions->getTimeout(),
                    'rpId' => $requestOptions->getRpId(),
                    'allowCredentials' => array_map(function($cred) {
                        return [
                            'type' => $cred->getType(),
                            'id' => base64url_encode($cred->getId())
                        ];
                    }, $requestOptions->getAllowCredentials()),
                    'userVerification' => $requestOptions->getUserVerification()
                ],
                'challenge_id' => $challengeId
            ];

        } catch (Exception $e) {
            $this->auditLogger->log('passkey_auth_options_error', 'Passkey auth options error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            
            return [
                'success' => false,
                'error' => 'Failed to generate authentication options',
                'code' => 'AUTH_OPTIONS_ERROR'
            ];
        }
    }

    /**
     * Verify authentication response
     */
    public function verifyAuthentication(array $response, ?string $challengeId = null): array
    {
        try {
            // Get credential ID from response
            $credentialId = base64url_decode($response['id']);
            
            // Find the credential source
            $credentialSource = $this->findOneByCredentialId($credentialId);
            if (!$credentialSource) {
                return [
                    'success' => false,
                    'error' => 'Credential not found',
                    'code' => 'CREDENTIAL_NOT_FOUND'
                ];
            }

            // Get user ID from credential
            $userId = (int)$credentialSource->getUserHandle();
            
            // Get stored challenge
            $challengeData = $this->getStoredChallenge($userId, 'authentication');
            if (!$challengeData) {
                return [
                    'success' => false,
                    'error' => 'Authentication challenge not found or expired',
                    'code' => 'CHALLENGE_NOT_FOUND'
                ];
            }

            // Verify the authentication response
            $updatedCredentialSource = $this->webauthnServer->loadAndCheckAssertionResponse(
                json_encode($response),
                $challengeData['challenge'],
                $_SERVER['HTTP_HOST'] ?? 'localhost'
            );

            // Update credential (sign count, last used)
            $this->updatePasskey($updatedCredentialSource);

            // Clear the challenge
            $this->clearChallenge($userId, 'authentication');

            // Get user data
            $user = $this->userRepository->find($userId);

            $this->auditLogger->log('passkey_auth_success', 'Passkey authentication successful', $userId);

            return [
                'success' => true,
                'user' => $user,
                'credential_id' => base64url_encode($credentialId),
                'message' => 'Authentication successful'
            ];

        } catch (Exception $e) {
            $this->auditLogger->log('passkey_auth_verification_error', 'Passkey auth verification error: ' . $e->getMessage(), null, 'unknown', 'error');
            
            return [
                'success' => false,
                'error' => 'Authentication verification failed',
                'code' => 'AUTH_VERIFICATION_ERROR'
            ];
        }
    }

    /**
     * Get user's passkeys
     */
    public function getUserPasskeys(int $userId): array
    {
        try {
            $sql = "SELECT * FROM passkeys WHERE user_id = ? AND is_active = 1 ORDER BY created_at DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            
            $passkeys = $stmt->fetchAll();
            
            // Remove sensitive data
            return array_map(function($passkey) {
                return [
                    'id' => $passkey['id'],
                    'device_name' => $passkey['device_name'],
                    'created_at' => $passkey['created_at'],
                    'last_used' => $passkey['last_used'],
                    'sign_count' => $passkey['sign_count']
                ];
            }, $passkeys);

        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Remove passkey
     */
    public function removePasskey(int $userId, int $passkeyId): array
    {
        try {
            // Verify passkey belongs to user
            $sql = "SELECT * FROM passkeys WHERE id = ? AND user_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$passkeyId, $userId]);
            $passkey = $stmt->fetch();

            if (!$passkey) {
                return [
                    'success' => false,
                    'error' => 'Passkey not found',
                    'code' => 'PASSKEY_NOT_FOUND'
                ];
            }

            // Check if this is the user's only authentication method
            $user = $this->userRepository->find($userId);
            $hasPassword = !empty($user['password_hash']);
            $hasMFA = $user['mfa_enabled'];
            $otherPasskeys = $this->getUserPasskeys($userId);
            $hasOtherPasskeys = count($otherPasskeys) > 1;

            if (!$hasPassword && !$hasMFA && !$hasOtherPasskeys) {
                return [
                    'success' => false,
                    'error' => 'Cannot remove the only authentication method',
                    'code' => 'LAST_AUTH_METHOD'
                ];
            }

            // Deactivate passkey
            $sql = "UPDATE passkeys SET is_active = 0 WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([$passkeyId]);

            if ($success) {
                $this->auditLogger->log('passkey_removed', 'Passkey removed', $userId);
                
                return [
                    'success' => true,
                    'message' => 'Passkey removed successfully'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Failed to remove passkey',
                    'code' => 'PASSKEY_REMOVAL_ERROR'
                ];
            }

        } catch (Exception $e) {
            $this->auditLogger->log('passkey_removal_error', 'Passkey removal error: ' . $e->getMessage(), $userId, 'unknown', 'error');
            
            return [
                'success' => false,
                'error' => 'Failed to remove passkey',
                'code' => 'PASSKEY_REMOVAL_ERROR'
            ];
        }
    }

    // PublicKeyCredentialSourceRepository interface methods

    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource
    {
        try {
            $sql = "SELECT * FROM passkeys WHERE credential_id = ? AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$publicKeyCredentialId]);
            $passkey = $stmt->fetch();

            if (!$passkey) {
                return null;
            }

            return PublicKeyCredentialSource::create(
                $passkey['credential_id'],
                'public-key',
                [],
                'none',
                false,
                null,
                $passkey['public_key'],
                (string)$passkey['user_id'],
                $passkey['sign_count']
            );

        } catch (Exception $e) {
            return null;
        }
    }

    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        try {
            $userId = (int)$publicKeyCredentialUserEntity->getId();
            $sql = "SELECT * FROM passkeys WHERE user_id = ? AND is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$userId]);
            $passkeys = $stmt->fetchAll();

            $sources = [];
            foreach ($passkeys as $passkey) {
                $sources[] = PublicKeyCredentialSource::create(
                    $passkey['credential_id'],
                    'public-key',
                    [],
                    'none',
                    false,
                    null,
                    $passkey['public_key'],
                    (string)$passkey['user_id'],
                    $passkey['sign_count']
                );
            }

            return $sources;

        } catch (Exception $e) {
            return [];
        }
    }

    public function saveCredentialSource(PublicKeyCredentialSource $publicKeyCredentialSource): void
    {
        // This is handled by storePasskey method
    }

    // Private helper methods

    private function getExistingCredentials(int $userId): array
    {
        $passkeys = $this->getUserPasskeys($userId);
        $credentials = [];

        foreach ($passkeys as $passkey) {
            // This would need the actual credential data from database
            // For now, return empty array
        }

        return $credentials;
    }

    private function storeChallenge(int $userId, string $challenge, string $type, string $deviceName = ''): string
    {
        $challengeId = bin2hex(random_bytes(16));
        $data = [
            'challenge' => $challenge,
            'type' => $type,
            'device_name' => $deviceName,
            'created_at' => time()
        ];

        $tempDir = sys_get_temp_dir();
        file_put_contents("{$tempDir}/webauthn_challenge_{$userId}_{$type}.json", json_encode($data));

        return $challengeId;
    }

    private function getStoredChallenge(int $userId, string $type): ?array
    {
        $tempDir = sys_get_temp_dir();
        $filePath = "{$tempDir}/webauthn_challenge_{$userId}_{$type}.json";

        if (!file_exists($filePath)) {
            return null;
        }

        $data = json_decode(file_get_contents($filePath), true);
        
        // Check if challenge has expired (5 minutes)
        if (time() - $data['created_at'] > 300) {
            unlink($filePath);
            return null;
        }

        return $data;
    }

    private function clearChallenge(int $userId, string $type): void
    {
        $tempDir = sys_get_temp_dir();
        $filePath = "{$tempDir}/webauthn_challenge_{$userId}_{$type}.json";

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    private function storePasskey(int $userId, PublicKeyCredentialSource $source, string $deviceName): ?int
    {
        try {
            $sql = "
                INSERT INTO passkeys (user_id, credential_id, public_key, device_name, sign_count)
                VALUES (?, ?, ?, ?, ?)
            ";
            $stmt = $this->db->prepare($sql);
            $success = $stmt->execute([
                $userId,
                $source->getPublicKeyCredentialId(),
                $source->getCredentialPublicKey(),
                $deviceName,
                $source->getCounter()
            ]);

            return $success ? (int)$this->db->lastInsertId() : null;

        } catch (Exception $e) {
            return null;
        }
    }

    private function updatePasskey(PublicKeyCredentialSource $source): void
    {
        try {
            $sql = "UPDATE passkeys SET sign_count = ?, last_used = NOW() WHERE credential_id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                $source->getCounter(),
                $source->getPublicKeyCredentialId()
            ]);

        } catch (Exception $e) {
            // Log error but don't fail authentication
            error_log("Failed to update passkey: " . $e->getMessage());
        }
    }
}

// Helper function for base64url encoding/decoding
if (!function_exists('base64url_encode')) {
    function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('base64url_decode')) {
    function base64url_decode(string $data): string {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
}