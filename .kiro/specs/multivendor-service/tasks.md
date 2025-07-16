# Implementation Plan

- [x] 1. Set up project structure and core interfaces



  - Create directory structure following established microservice pattern
  - Set up composer.json with PSR-4 autoloading for Antinna\Multivendor namespace
  - Create core interface definitions for service boundaries
  - Set up basic configuration management with environment variables

  - _Requirements: 1.1, 2.1, 3.1_

- [x] 2. Implement database schema and migrations


  - Create MySQL database schema for vendors, products, inventory_batches, and customer_subscriptions tables
  - Write migration scripts with proper foreign key constraints and indexes
  - Implement database connection utilities using PDO
  - Create base repository pattern with CRUD operations
  - _Requirements: 1.1, 2.2, 3.1, 8.3_

- [x] 3. Build vendor management core functionality



  - [x] 3.1 Implement vendor registration service


    - Create VendorRegistrationService class with business validation
    - Implement KYC document validation and FSSAI license verification
    - Write unit tests for registration workflow and validation logic
    - _Requirements: 1.1, 1.4, 8.3_


  - [x] 3.2 Create vendor profile management

    - Implement VendorProfileManager for profile updates and maintenance
    - Create audit trail functionality for profile changes
    - Write tests for profile update scenarios and audit logging
    - _Requirements: 1.5, 1.1_

  - [x] 3.3 Implement multi-role vendor access


    - Create VendorRoleManager for owner/manager/delivery staff roles
    - Implement role-based access control and permissions
    - Write tests for role assignment and permission validation
    - _Requirements: 1.3, 5.1_

- [x] 4. Develop product management system



  - [x] 4.1 Create product catalog management


    - Implement ProductCatalogManager with perishable-specific attributes
    - Add validation for expiry dates, organic certification, and packaging types
    - Write tests for product creation and validation rules
    - _Requirements: 2.1, 2.2, 8.1_

  - [x] 4.2 Build inventory and batch tracking


    - Create InventoryTracker for stock level monitoring
    - Implement BatchTraceabilityService for batch-to-order mapping
    - Write tests for inventory updates and traceability chain
    - _Requirements: 2.2, 2.5, 8.1, 8.4_

  - [x] 4.3 Implement expiry management automation



    - Create ExpiryManager for automated expired product removal
    - Implement background job for cart cleanup and search filtering
    - Write tests for expiry detection and automated cleanup




    - _Requirements: 2.3, 2.4_

- [x] 5. Build subscription management engine

  - [x] 5.1 Create subscription CRUD operations



    - Implement SubscriptionManager for customer subscription handling
    - Add validation for delivery preferences and scheduling constraints
    - Write tests for subscription creation and modification workflows



    - _Requirements: 3.1, 3.3_





  - [x] 5.2 Develop automated order generation



    - Create OrderGenerator with nightly cron job functionality (2AM execution)
    - Implement vendor capacity checking before order confirmation
    - Write tests for automated order creation and capacity validation


    - _Requirements: 3.2, 3.5, 5.4_

  - [x] 5.3 Implement subscription pause/resume logic


    - Add pause and resume functionality to SubscriptionManager



    - Create scheduling logic for temporary subscription holds
    - Write tests for pause/resume scenarios and edge cases




    - _Requirements: 3.3, 3.4_

- [x] 6. Develop delivery coordination system



  - [x] 6.1 Create delivery slot management


    - Implement DeliverySlotManager with freshness window constraints
    - Add cold-chain capability validation for temperature-sensitive products
    - Write tests for slot availability and freshness window enforcement



    - _Requirements: 4.2, 4.3, 4.1_

  - [x] 6.2 Build order consolidation logic


    - Create OrderConsolidator to merge subscription and on-demand orders
    - Implement grouping by location, vendor, and delivery slot
    - Write tests for order merging scenarios and optimization
    - _Requirements: 4.4, 4.5_

  - [x] 6.3 Implement delivery failure handling


    - Create DeliveryFailureHandler for missed delivery processing
    - Implement automatic refund triggering for failed deliveries
    - Write tests for failure scenarios and refund processing
    - _Requirements: 4.3, 6.3_

- [x] 7. Build vendor dashboard and analytics


  - [x] 7.1 Create dashboard data aggregation


    - Implement VendorDashboardService for performance metrics compilation
    - Create SalesAnalytics for revenue and order tracking
    - Write tests for data aggregation accuracy and performance
    - _Requirements: 5.1, 5.2, 5.5_

  - [x] 7.2 Implement feedback and rating system

    - Create FeedbackAggregator for customer feedback processing
    - Implement rating calculation and vendor performance scoring
    - Write tests for feedback processing and rating algorithms
    - _Requirements: 5.1, 5.4_

  - [x] 7.3 Build automated reporting

    - Create ReportGenerator for vendor business reports
    - Implement scheduled report generation and delivery
    - Write tests for report accuracy and generation scheduling
    - _Requirements: 5.2, 5.5_

- [x] 8. Implement notification system



  - [x] 8.1 Create notification dispatcher










    - Implement NotificationDispatcher with multi-channel routing (Firebase, SMS, Email)
    - Create notification preference management for vendors
    - Write tests for notification routing and delivery confirmation


    - _Requirements: 7.1, 7.2, 7.3, 7.5_





  - [x] 8.2 Build stock alert system


    - Create StockAlertService for predictive inventory notifications
    - Implement low stock detection and automated alert generation

    - Write tests for stock threshold detection and alert timing
    - _Requirements: 2.4, 7.1, 5.4_

  - [x] 8.3 Implement delivery notifications


    - Create DeliveryNotificationService for status updates
    - Add real-time notification for delivery failures and successes
    - Write tests for delivery status notification workflows
    - _Requirements: 7.2, 7.4_

- [x] 9. Develop payment integration


  - [x] 9.1 Create payout calculation service


    - Implement payout calculation logic with fee deductions
    - Create integration hooks with payment service API
    - Write tests for payout calculation accuracy and API integration
    - _Requirements: 6.1, 6.3_

  - [x] 9.2 Implement vendor promotion system


    - Create promotion management for vendor-specific discounts
    - Implement coupon validation and payout adjustment logic
    - Write tests for promotion application and payout calculations
    - _Requirements: 6.2, 6.1_

- [x] 10. Build compliance and traceability system

  - [x] 10.1 Create food safety compliance tracking


    - Implement FSSAIValidator for license validation
    - Create compliance record management and audit trails
    - Write tests for compliance validation and record keeping
    - _Requirements: 8.1, 8.3, 8.5_

  - [x] 10.2 Implement incident traceability


    - Create TraceabilityReporter for complete product journey tracking
    - Implement batch recall functionality and customer notification
    - Write tests for traceability chain integrity and recall processes
    - _Requirements: 8.1, 8.2, 8.4_

- [x] 11. Create API endpoints and controllers

  - [x] 11.1 Build vendor management APIs

    - Create REST endpoints for vendor registration, profile management, and role assignment
    - Implement proper authentication and authorization middleware
    - Write integration tests for all vendor management endpoints
    - _Requirements: 1.1, 1.3, 1.5_

  - [x] 11.2 Implement product and inventory APIs



    - Create endpoints for product catalog management and inventory tracking
    - Add batch management and expiry handling endpoints
    - Write integration tests for product and inventory operations

    - _Requirements: 2.1, 2.2, 2.5_

  - [x] 11.3 Build subscription and delivery APIs



    - Create endpoints for subscription management and order generation
    - Implement delivery slot management and capacity checking APIs
    - Write integration tests for subscription and delivery workflows
    - _Requirements: 3.1, 3.2, 4.1, 4.4_

- [x] 12. Implement background job processing



  - [x] 12.1 Create cron job infrastructure


    - Set up job scheduling system for automated tasks
    - Implement job queue management with failure handling
    - Write tests for job execution reliability and error recovery
    - _Requirements: 3.2, 2.3_

  - [x] 12.2 Build automated maintenance tasks


    - Create cleanup jobs for expired products and old data
    - Implement automated report generation and notification sending
    - Write tests for maintenance task execution and scheduling
    - _Requirements: 2.3, 5.2, 7.1_

- [x] 13. Add comprehensive error handling and logging










  - Create centralized error handling with proper HTTP status codes
  - Implement structured logging for debugging and monitoring
  - Add request/response logging for API audit trails
  - Write tests for error scenarios and logging functionality
  - _Requirements: All requirements for system reliability_

- [ ] 14. Build admin panel and migration management system
  - [ ] 14.1 Create admin authentication system
    - Implement AdminAuthenticator with environment variable credential support
    - Add fallback to default "admin"/"admin" credentials when env vars not set
    - Create secure session management for admin panel access
    - Write tests for authentication scenarios and credential validation
    - _Requirements: 9.1, 9.2_

  - [ ] 14.2 Develop migration orchestration system
    - Create MigrationOrchestrator to coordinate migrations across all microservices
    - Implement HTTP client integration for auth, pay, social, delivery service migration APIs
    - Add real-time progress tracking with WebSocket support
    - Write tests for multi-service migration coordination and error handling
    - _Requirements: 9.3, 9.4, 9.6_

  - [ ] 14.3 Build migration history and monitoring
    - Implement MigrationHistoryTracker for tracking execution status across services
    - Create ServiceHealthChecker for monitoring microservice availability
    - Add migration rollback functionality with detailed error reporting
    - Write tests for history tracking and rollback scenarios
    - _Requirements: 9.5, 9.7_

  - [ ] 14.4 Create admin panel web interface
    - Build responsive web interface for migration management
    - Implement real-time progress display with WebSocket connections
    - Add one-click setup functionality for initial system installation
    - Create service health dashboard with status monitoring
    - Write integration tests for admin panel workflows
    - _Requirements: 9.3, 9.4, 9.6_

- [ ] 15. Create configuration and deployment setup
  - Set up environment-specific configuration management
  - Create app.yaml and wasmer.toml for Wasmer deployment
  - Configure database connections and external service integrations
  - Add ADMIN_USERNAME and ADMIN_PASSWORD environment variables to app.yaml
  - Write deployment verification tests
  - _Requirements: System deployment and configuration, 9.1, 9.2_