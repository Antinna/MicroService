We have Github workflow to deploy the Microservices
we have different microservices like auth, pay, social (for social media kind chat and video call), delivery and multivendor 

for MySQL we have some fixed ## Environment Variables
Services use environment-based configuration for:
- `DB_HOST`, `DB_PORT`, `DB_NAME`: Database connection
- `DB_USERNAME`, `DB_PASSWORD`: Database credentials 
Also you can add more Variables but you have no guide me what variable you have used and for what microservice , also do update Readme file for that to show variables in Use


✅ Core Responsibilities of multivendor service:
Responsibility	Description
Vendor Onboarding	Registration, KYC, location, business type (dairy, veggies, etc.)
Vendor Product Management	Vendors can add/edit/delete items they supply
Pricing & Availability	Vendors manage price per unit, stock level, batch
Vendor Subscription Setup	Vendors can enable recurring orders for their products
Vendor Dashboard APIs	Orders, sales reports, delivery status, feedback
Vendor Role Management	Multiple logins (owner, manager, delivery staff)
Vendor Notification Hooks	Low stock alerts, delivery failures, etc.
Payout Management Hooks	Integrate with payment service to trigger vendor settlements
Vendor-specific Promotions	Vendors run own coupons/discounts


# we Do also run Dairy Products and vegitables delivery at doorstep with or without subscription based models for these we can have
 Expiry

Origin/farm

Organic tag

Packaging type (loose, bottle, sealed)(for dairy milk)

for these i need 
Smart Features:
Auto-remove expired items from cart/search

Predictive stock alerts for daily needs

Warehouse ↔ Local delivery sync


For daily/weekly recurring orders.

Key Features:
Customer preferences: quantity, time, delivery days

Pause/resume logic

Vendor delivery capacity checks

Auto-order generation nightly (e.g., 2AM)


This service merges:

Subscription Orders

On-demand Orders

By:

Location

Vendor

Delivery slot

Specific to perishables:

Vendors define cold-chain capable or not

Delivery slots have freshness windows (e.g., before 8AM for milk)

If missed, item flagged as "return to vendor" with refund

For milk/veggies:

Store FSSAI license, farm/produce origin

Batch → Vendor → Location → Order mapping

Use case:

Trace food safety incidents

Prove freshness & source compliance

and here for notification-service we use firebase notification (single firebase project for all our microservices) and SMS as well as Email


# Pay 
payments, refunds, payouts and more
