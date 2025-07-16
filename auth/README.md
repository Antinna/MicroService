Auth System

Auth Type	Typical Usage	Notes
Passkeys (WebAuthn)	Passwordless, biometric + hardware keys	Best phishing-resistant method
Magic Link	Email passwordless login	Great UX, email-dependent
Social Logins	OAuth2 via Google/Facebook/Apple/etc.	Easy onboarding, external dependency
Passwords + MFA	Traditional login plus OTP or security key	Baseline fallback and security
Phone Auth (SMS/OTP)	For fallback MFA or direct login	Use cautiously due to SIM swap risks
API Tokens	Programmatic access	Scoped, revocable
SSO	Enterprise or multi-app environments	Central auth management
