# Simple Gym Admin Manager Plugin

A comprehensive WordPress REST API plugin for dual-gym management with user management, memberships, product sales tracking, and analytics.

## 🎯 Overview

**Simple Gym Admin** is a backend-only WordPress plugin designed to power React/frontend applications with robust gym management capabilities. It supports **two separate gym locations** (Afrgym One and Afrgym Two) with isolated authentication while sharing user and membership databases.

### Key Features

- 🏋️ **Dual Gym System** - Two completely separate admin systems sharing the same member database
- 👥 **User Management** - Full CRUD operations for gym members
- 💳 **Membership Management** - Time-based and visit-based membership plans
- 🛍️ **Product Sales Tracking** - Comprehensive inventory and sales management
- 📊 **Analytics & Statistics** - Gym-specific dashboards and reporting
- ✉️ **Email System** - Automated notifications and bulk messaging
- 🔐 **QR Code Integration** - Member check-in and lookup
- 📧 **Email Verification** - Optional OTP-based verification system
- ⏸️ **Membership Pause** - Pause/unpause memberships with date extension

## 🚀 Installation

### Prerequisites

- WordPress 5.8 or higher
- PHP 7.4 or higher
- MySQL 5.7 or higher
- **Required Plugins:**
  - Paid Memberships Pro (PMPro)
  - Ben's QR Code Manager (optional)
  - WP Mail SMTP (recommended)

### Setup

1. **Upload the plugin**
   ```bash
   cd wp-content/plugins/
   git clone <repository-url> afrgym-bend
   ```

2. **Activate the plugin**
   - Go to WordPress Admin → Plugins
   - Find "Simple Gym Admin" and click "Activate"

3. **Verify tables created**
   - Check your database for new tables with `wp_gym_` prefix

4. **Change default credentials immediately!**

## 🔐 Default Admin Credentials

### Afrgym One
- **Username:** `gymone_admin`
- **Password:** `GymOne2024!`
- **Email:** `admin@gymone.local`
- **Login Endpoint:** `/auth/login/gym-one`

### Afrgym Two
- **Username:** `gymtwo_admin`
- **Password:** `GymTwo2024!`
- **Email:** `admin@gymtwo.local`
- **Login Endpoint:** `/auth/login/gym-two`

⚠️ **IMPORTANT:** Change these credentials immediately after first login!

## 📡 API Documentation

All endpoints are prefixed with: `/wp-json/gym-admin/v1`

### Authentication (7 endpoints)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/auth/login/gym-one` | Login to Gym One |
| POST | `/auth/login/gym-two` | Login to Gym Two |
| POST | `/auth/login` | Legacy login (defaults to Gym One) |
| POST | `/auth/logout` | Logout current session |
| GET | `/auth/validate` | Validate JWT token |
| POST | `/auth/create-admin` | Create new admin (super_admin only) |
| POST | `/auth/change-password` | Change admin password |

### User Management (5 endpoints)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/users` | List all users (paginated, searchable) |
| GET | `/users/{id}` | Get single user details |
| POST | `/users` | Create new user |
| PUT | `/users/{id}` | Update user information |
| DELETE | `/users/{id}` | Delete user |

### Memberships (7 endpoints)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/memberships` | List all membership levels |
| POST | `/users/{id}/membership` | Assign membership to user |
| PUT | `/users/{id}/membership` | Update user membership |
| GET | `/memberships/expiring` | Get expiring memberships |
| POST | `/memberships/{user_id}/pause` | Pause membership |
| POST | `/memberships/{user_id}/unpause` | Unpause membership |
| GET | `/memberships/{user_id}/pause-status` | Get pause status |

### Products (12 endpoints)

| Method | Endpoint | Description | Access |
|--------|----------|-------------|--------|
| GET | `/products` | List all products | All |
| GET | `/products/{id}` | Get single product | All |
| POST | `/products` | Create new product | Super Admin |
| PUT | `/products/{id}` | Update product | Super Admin |
| DELETE | `/products/{id}` | Delete product | Super Admin |
| POST | `/products/{id}/sale` | Record product sale | All Admins |
| GET | `/products/stats/monthly` | Monthly sales stats | All |
| GET | `/products/stats/weekly` | Weekly sales stats | All |
| GET | `/products/stats/analytics` | Detailed analytics | All |
| GET | `/products/stats/top-selling` | Top products | All |
| GET | `/products/low-stock` | Low inventory alerts | All |
| GET | `/products/categories` | Product categories | All |

### Statistics (13 endpoints)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/stats/dashboard` | Main dashboard stats (gym-filtered) |
| GET | `/stats/dashboard/gym-one` | Gym One specific stats |
| GET | `/stats/dashboard/gym-two` | Gym Two specific stats |
| GET | `/stats/daily` | Daily statistics |
| GET | `/stats/monthly` | Monthly statistics |
| GET | `/stats/range` | Date range statistics |
| GET | `/stats/growth` | Growth trends |
| GET | `/stats/activities` | Recent activities |
| GET | `/stats/pauses` | Membership pause statistics |
| GET | `/stats/admin-activity` | Admin activity logs |
| GET | `/stats/expiring` | Expiring memberships summary |

### QR Codes (4 endpoints)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/qr/user/{id}` | Get user's QR code |
| POST | `/qr/generate/{id}` | Generate/update QR code |
| GET | `/qr/lookup?unique_id={code}` | Lookup user by QR |
| POST | `/qr/lookup-checkin` | QR-based check-in |

### Email System (3 endpoints)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/emails/send` | Send single email |
| POST | `/emails/bulk` | Send bulk emails |
| GET | `/emails/templates` | Get email templates |

### Email Verification (5 endpoints)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/auth/send-otp` | Send OTP to email |
| POST | `/auth/verify-otp` | Verify OTP code |
| POST | `/auth/resend-otp` | Resend OTP |
| GET | `/users/{id}/email-status` | Check verification status |
| POST | `/users/{id}/verify-email` | Manual email verification |

**Total: 56 API Endpoints**

## 🔧 Usage Examples

### Authentication

```javascript
// Login to Gym One
const response = await fetch('/wp-json/gym-admin/v1/auth/login/gym-one', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    username: 'gymone_admin',
    password: 'GymOne2024!'
  })
});

const { token, gym_type, admin } = await response.json();
// Store token for subsequent requests
```

### Record Product Sale

```javascript
// Record a sale (all admins can do this)
const response = await fetch('/wp-json/gym-admin/v1/products/1/sale', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': `Bearer ${token}`
  },
  body: JSON.stringify({
    quantity: 3,
    note: 'Sold to member John - Cash payment'
  })
});

const result = await response.json();
// Returns: sale details, updated inventory, total amount
```

### Get Monthly Statistics

```javascript
// Get gym-specific monthly stats
const response = await fetch('/wp-json/gym-admin/v1/stats/dashboard?month=2025-01', {
  headers: { 'Authorization': `Bearer ${token}` }
});

const stats = await response.json();
// Automatically filtered by logged-in admin's gym
```

## 🏗️ Database Schema

### Custom Tables

- `wp_gym_admins` - Gym One administrators
- `wp_gym_admins_two` - Gym Two administrators
- `wp_gym_admin_sessions` - JWT authentication sessions
- `wp_gym_products` - Product catalog (shared)
- `wp_gym_product_sales` - Sales transactions (gym-tracked)
- `wp_gym_product_activity` - Product activity logs
- `wp_gym_email_logs` - Email sending logs
- `wp_gym_user_notes` - Admin notes on users
- `wp_gym_membership_pauses` - Pause/unpause history
- `wp_gym_settings` - Plugin configuration

### WordPress Integration

- Uses `wp_users` for member data
- Uses `wp_usermeta` for extended member info
- Integrates with Paid Memberships Pro tables
- Stores QR codes in `wp_usermeta`

## 🛡️ Security Features

- JWT-based authentication with expiry
- Separate admin tables per gym location
- Role-based access control (super_admin, admin)
- Password hashing with WordPress standards
- Input validation and sanitization
- SQL injection prevention
- XSS protection
- Rate limiting on sensitive endpoints

## 🔄 Dual Gym System

### What's Shared

✅ User database (members belong to both gyms)  
✅ Membership data (PMPro integration)  
✅ Product catalog (both gyms see same products)  
✅ QR codes and email logs (with gym tracking)

### What's Isolated

❌ Admin accounts (completely separate)  
❌ Authentication sessions (gym-specific tokens)  
❌ Product sales data (tracked per gym)  
❌ Statistics and analytics (gym-filtered)  
❌ Activity feeds (shows only own gym's actions)

### Gym Identifiers

- **Gym One:** `afrgym_one`
- **Gym Two:** `afrgym_two`

All activities, sales, and notes are tagged with the gym identifier for proper attribution and filtering.

## 🛍️ Product Management

### Permissions

| Action | Super Admin | Regular Admin |
|--------|-------------|---------------|
| Create Product | ✅ | ❌ |
| Update Product | ✅ | ❌ |
| Delete Product | ✅ | ❌ |
| Record Sale | ✅ | ✅ |
| View Statistics | ✅ | ✅ (own gym only) |

### Product Features

- **Multi-image support** - Upload multiple product photos
- **SKU management** - Unique product identifiers
- **Category system** - Dynamic product categorization
- **Inventory tracking** - Real-time stock monitoring
- **Low stock alerts** - Configurable threshold notifications
- **Sales analytics** - Gym-specific performance metrics
- **Monthly reports** - Automated email reports (1st of each month)

### Inventory Logic

```
quantity_left = quantity - total_sold
```

Sales validation ensures sufficient stock before recording transactions.

## 📧 Email System

### Automated Emails

- Welcome emails for new members
- Membership expiry notifications (7 days before)
- OTP verification codes
- Membership pause/unpause confirmations
- Monthly product sales reports

### Email Templates

Located in `/templates/`:
- `welcome-email.html`
- `membership-expiry-email.html`
- `email-otp.html`
- `membership-pause-email.html`
- `membership-unpause-email.html`
- `product-monthly-report.html`

## 🎫 Visit-Based Memberships

Plans 12 and 13 ("3x a week / Month") use a visit-based system:

- **12 visits per month** allocation
- **Admin-controlled check-ins** (no self check-in)
- **Monthly reset cycle** based on membership start date
- **One check per day** maximum
- **Dual expiry logic** - expires when visits depleted OR date reached

### Visit Tracking

```javascript
// Check in a member
POST /wp-json/gym-admin/v1/checkin/{user_id}

// Get visit statistics
GET /wp-json/gym-admin/v1/memberships/visit-stats

// Find members with low visits
GET /wp-json/gym-admin/v1/memberships/low-visits
```

## ⏸️ Membership Pause Feature

Admins can pause memberships temporarily:

- Pause duration extends the membership end date
- Tracked with gym identifier
- Email notifications sent automatically
- History maintained in database

```javascript
// Pause membership
POST /wp-json/gym-admin/v1/memberships/{user_id}/pause
Body: { "pause_reason": "Medical leave" }

// Unpause membership
POST /wp-json/gym-admin/v1/memberships/{user_id}/unpause
Body: { "unpause_reason": "Resumed training" }
```

## 🔌 Plugin Integration

### Required Integrations

1. **Paid Memberships Pro** - Membership level management (read-only)
2. **WP Mail SMTP** - Reliable email delivery

### Optional Integrations

1. **Ben's QR Code Manager** - QR code generation and scanning

## 📊 Automated Tasks

### WP-Cron Jobs

- **Product monthly reports** - 1st of each month at 9:00 AM
- **Membership expiry emails** - Daily check for expiring memberships
- **Visit resets** - Monthly reset based on individual cycles

## 🐛 Troubleshooting

### Common Issues

**JWT Token Issues**
```bash
# Check .htaccess has authorization header
# Add if missing:
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

**Email Not Sending**
- Verify WP Mail SMTP configuration
- Check email logs table: `wp_gym_email_logs`
- Test with WordPress default email function

**Database Tables Missing**
```sql
-- Check if tables exist
SHOW TABLES LIKE 'wp_gym_%';

-- Deactivate and reactivate plugin to recreate tables
```

**Permission Errors**
- Verify admin role in database
- Check JWT token contains correct gym_identifier
- Ensure super_admin role for product CRUD operations

## 📝 Development

### File Structure

```
afrgym-bend/
├── simple-gym-admin.php          # Main plugin file
├── includes/
│   ├── class-gym-activator.php   # Database setup
│   ├── class-gym-admin.php       # Core admin class
│   ├── api/                       # REST API endpoints
│   │   ├── class-auth-endpoints.php
│   │   ├── class-user-endpoints.php
│   │   ├── class-membership-endpoints.php
│   │   ├── class-product-endpoints.php
│   │   ├── class-email-endpoints.php
│   │   ├── class-qr-endpoints.php
│   │   ├── class-stats-endpoints.php
│   │   └── class-otp-endpoints.php
│   └── services/                  # Business logic
│       ├── class-admin-service.php
│       ├── class-email-service.php
│       ├── class-membership-service.php
│       ├── class-product-service.php
│       ├── class-qr-service.php
│       └── class-stats-service.php
└── templates/                     # Email templates
    ├── welcome-email.html
    ├── membership-expiry-email.html
    ├── email-otp.html
    ├── membership-pause-email.html
    ├── membership-unpause-email.html
    └── product-monthly-report.html
```

### Architecture Notes

- **API-only plugin** - No WordPress admin UI
- **RESTful design** - JSON responses only
- **Service layer pattern** - Business logic separated from endpoints
- **Gym-aware operations** - All actions tracked by gym identifier
- **Security-first** - JWT authentication, input validation, sanitization

## 🤝 Contributing

This is a private gym management system. For feature requests or bug reports, contact the development team.

## 📄 License

Proprietary - All rights reserved

## 🆘 Support

For technical support or questions:
- Check the troubleshooting section above
- Review API documentation
- Contact system administrator

## 📋 Changelog

### Version 5.1 (Current)
- ✅ Complete product management system
- ✅ Product CRUD endpoints (super_admin only)
- ✅ Sales recording (all admins)
- ✅ Gym-specific sales tracking and statistics
- ✅ Monthly automated product reports
- ✅ Low stock alerts
- ✅ Multi-image support for products
- ✅ Product category system
- ✅ Top-selling products analytics

### Version 5.0
- Initial dual-gym system
- Visit-based membership support
- Membership pause feature
- Email verification system
- QR code integration
- Comprehensive statistics

---

**Built with ❤️ for Afrgym One & Afrgym Two**