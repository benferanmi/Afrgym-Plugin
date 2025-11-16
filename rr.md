# 📦 Product Management API Documentation

## Overview
Complete product management system integrated with the dual-gym admin architecture. Tracks inventory, sales, and generates analytics per gym.

---

## 🔐 Authentication
All endpoints require JWT token in Authorization header:
```
Authorization: Bearer {your_jwt_token}
```

## 🎯 Permission Levels
- **Super Admin**: Full CRUD operations on products
- **Admin**: Can only record sales (update sold count)

---

## 📋 API Endpoints (11 Total)

### 1. Get All Products
**GET** `/wp-json/gym-admin/v1/products`

Get paginated list of products with filtering and search.

**Query Parameters:**
```json
{
  "page": 1,
  "per_page": 20,
  "search": "protein",
  "status": "active|inactive|all",
  "orderby": "name|price|quantity|created_at|total_sold",
  "order": "asc|desc"
}
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Protein Shake",
      "price": 5000.00,
      "description": "Premium whey protein",
      "sku": "PRO-001",
      "category": "Supplements",
      "quantity": 100,
      "total_sold": 45,
      "quantity_left": 55,
      "images": [
        "https://example.com/image1.jpg",
        "https://example.com/image2.jpg"
      ],
      "status": "active",
      "created_by_gym": "afrgym_one",
      "created_at": "2025-01-15 10:30:00",
      "updated_at": "2025-01-20 14:20:00"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total_items": 45,
    "total_pages": 3
  }
}
```

---

### 2. Get Single Product
**GET** `/wp-json/gym-admin/v1/products/{id}`

Get detailed information about a specific product.

**Response:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Protein Shake",
    "price": 5000.00,
    "description": "Premium whey protein shake",
    "sku": "PRO-001",
    "category": "Supplements",
    "quantity": 100,
    "total_sold": 45,
    "quantity_left": 55,
    "images": ["https://example.com/image1.jpg"],
    "status": "active",
    "created_by_gym": "afrgym_one",
    "created_at": "2025-01-15 10:30:00"
  }
}
```

---

### 3. Add Product (Super Admin Only)
**POST** `/wp-json/gym-admin/v1/products`

Create a new product.

**Request Body:**
```json
{
  "name": "Energy Bar",
  "price": 1500.00,
  "description": "High-protein energy bar",
  "quantity": 200,
  "sku": "BAR-001",
  "category": "Snacks",
  "images": [
    "https://example.com/bar1.jpg",
    "https://example.com/bar2.jpg"
  ],
  "status": "active"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Product added successfully",
  "data": {
    "id": 15,
    "name": "Energy Bar",
    "price": 1500.00,
    "quantity": 200,
    "total_sold": 0,
    "quantity_left": 200,
    "created_by_gym": "afrgym_one"
  }
}
```

---

### 4. Update Product (Super Admin Only)
**PUT** `/wp-json/gym-admin/v1/products/{id}`

Update product details or adjust inventory quantity.

**Request Body (all fields optional):**
```json
{
  "name": "Updated Product Name",
  "price": 2000.00,
  "description": "Updated description",
  "quantity": 150,
  "sku": "NEW-SKU",
  "category": "New Category",
  "images": ["https://example.com/new-image.jpg"],
  "status": "inactive"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Product updated successfully",
  "data": {
    "id": 15,
    "name": "Updated Product Name",
    "price": 2000.00,
    "quantity": 150,
    "total_sold": 0,
    "quantity_left": 150
  }
}
```

---

### 5. Delete Product (Super Admin Only)
**DELETE** `/wp-json/gym-admin/v1/products/{id}`

Permanently delete a product and all associated sales records.

**Response:**
```json
{
  "success": true,
  "message": "Product deleted successfully"
}
```

---

### 6. Record Sale (Admin + Super Admin)
**POST** `/wp-json/gym-admin/v1/products/{id}/sale`

Record a product sale. Automatically tracked by gym.

**Request Body:**
```json
{
  "quantity": 3,
  "note": "Sold to John Doe - Cash payment"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Sale recorded successfully",
  "data": {
    "sale_id": 123,
    "product": {
      "id": 1,
      "name": "Protein Shake",
      "quantity_left": 52
    },
    "quantity_sold": 3,
    "total_amount": 15000.00,
    "gym_identifier": "afrgym_one"
  }
}
```

---

### 7. Get Monthly Statistics (Gym-Filtered)
**GET** `/wp-json/gym-admin/v1/products/stats/monthly`

Get comprehensive monthly sales statistics for the logged-in gym.

**Query Parameters:**
```json
{
  "month": "2025-01"  // Optional, defaults to current month
}
```

**Response:**
```json
{
  "success": true,
  "gym_identifier": "afrgym_one",
  "gym_name": "Afrgym One",
  "data": {
    "month": "2025-01",
    "summary": {
      "total_units_sold": 245,
      "total_revenue": 456750.00,
      "transaction_count": 89,
      "average_transaction_value": 5131.46
    },
    "products_breakdown": [
      {
        "id": 1,
        "name": "Protein Shake",
        "price": 5000.00,
        "total_sold": 85,
        "total_revenue": 425000.00
      },
      {
        "id": 3,
        "name": "Energy Bar",
        "price": 1500.00,
        "total_sold": 120,
        "total_revenue": 180000.00
      }
    ],
    "daily_stats": [
      {
        "date": "2025-01-15",
        "units_sold": 12,
        "revenue": 25000.00
      }
    ]
  }
}
```

---

### 8. Get Weekly Statistics (Gym-Filtered)
**GET** `/wp-json/gym-admin/v1/products/stats/weekly`

Get weekly breakdown of product sales.

**Query Parameters:**
```json
{
  "date": "2025-01-15"  // Optional, any date in the week
}
```

**Response:**
```json
{
  "success": true,
  "gym_identifier": "afrgym_one",
  "gym_name": "Afrgym One",
  "data": {
    "week_start": "2025-01-13",
    "week_end": "2025-01-19",
    "summary": {
      "total_units_sold": 56,
      "total_revenue": 78500.00
    },
    "daily_breakdown": [
      {
        "date": "2025-01-13",
        "day_name": "Monday",
        "units_sold": 8,
        "revenue": 12000.00
      },
      {
        "date": "2025-01-14",
        "day_name": "Tuesday",
        "units_sold": 12,
        "revenue": 18500.00
      }
    ]
  }
}
```

---

### 9. Get Product Analytics
**GET** `/wp-json/gym-admin/v1/products/stats/analytics`

Get comprehensive analytics for specified period.

**Query Parameters:**
```json
{
  "period": "week|month|3months|6months|year"
}
```

**Response:**
```json
{
  "success": true,
  "gym_identifier": "afrgym_one",
  "data": {
    "period": "month",
    "date_range": {
      "start": "2024-12-21",
      "end": "2025-01-20"
    },
    "summary": {
      "total_units_sold": 450,
      "total_revenue": 875000.00,
      "products_sold_count": 12
    },
    "top_by_revenue": [
      {
        "id": 1,
        "name": "Protein Shake",
        "units_sold": 150,
        "revenue": 750000.00
      }
    ],
    "top_by_units": [
      {
        "id": 3,
        "name": "Energy Bar",
        "units_sold": 200,
        "revenue": 300000.00
      }
    ]
  }
}
```

---

### 10. Get Top Selling Products (Gym-Filtered)
**GET** `/wp-json/gym-admin/v1/products/stats/top-selling`

Get best-selling products for the logged-in gym.

**Query Parameters:**
```json
{
  "limit": 10,
  "period": "week|month|3months|6months|year|all"
}
```

**Response:**
```json
{
  "success": true,
  "gym_identifier": "afrgym_one",
  "data": [
    {
      "id": 1,
      "name": "Protein Shake",
      "price": 5000.00,
      "category": "Supplements",
      "units_sold": 350,
      "revenue": 1750000.00,
      "transaction_count": 145
    },
    {
      "id": 3,
      "name": "Energy Bar",
      "price": 1500.00,
      "category": "Snacks",
      "units_sold": 280,
      "revenue": 420000.00,
      "transaction_count": 98
    }
  ],
  "limit": 10,
  "period": "month"
}
```

---

### 11. Get Low Stock Products
**GET** `/wp-json/gym-admin/v1/products/low-stock`

Get products with inventory below threshold.

**Query Parameters:**
```json
{
  "threshold": 10  // Default: 10
}
```

**Response:**
```json
{
  "success": true,
  "data": [
    {
      "id": 5,
      "name": "Resistance Band",
      "sku": "RES-001",
      "price": 3000.00,
      "quantity": 50,
      "total_sold": 45,
      "quantity_left": 5,
      "status": "active"
    },
    {
      "id": 8,
      "name": "Gym Towel",
      "sku": "TOW-001",
      "price": 1000.00,
      "quantity": 30,
      "total_sold": 22,
      "quantity_left": 8,
      "status": "active"
    }
  ],
  "count": 2,
  "threshold": 10
}
```

---

### 12. Get Product Categories
**GET** `/wp-json/gym-admin/v1/products/categories`

Get list of all product categories.

**Response:**
```json
{
  "success": true,
  "data": [
    "Supplements",
    "Snacks",
    "Equipment",
    "Apparel",
    "Accessories"
  ],
  "count": 5
}
```

---

## 📊 Database Tables Created

### 1. `wp_gym_products`
Stores product information.

```sql
- id (Primary Key)
- name
- price (decimal)
- description (text)
- sku (unique)
- category
- quantity (total available)
- total_sold (cumulative)
- images (JSON array)
- status (active/inactive)
- created_by_gym (afrgym_one/afrgym_two)
- created_at
- updated_at
```

### 2. `wp_gym_product_sales`
Tracks each sale transaction with gym identifier.

```sql
- id (Primary Key)
- product_id
- quantity
- price_at_sale
- total_amount
- admin_id
- gym_identifier (afrgym_one/afrgym_two)
- note
- sale_date
- created_at
```

### 3. `wp_gym_product_activity`
Logs all product-related activities.

```sql
- id (Primary Key)
- product_id
- admin_id
- gym_identifier
- action (created/updated/deleted/sale)
- description
- created_at
```

---

## 🔄 Automatic Monthly Reports

The system automatically sends monthly product reports via email on the 1st of each month at 9:00 AM.

### Report Contents:
- ✅ Total revenue for the month
- ✅ Total units sold
- ✅ Transaction count
- ✅ Top 5 products
- ✅ Weekly breakdown
- ✅ Low stock alerts
- ✅ **Separate report for each gym**

### Email Configuration:
```php
// In wp_gym_settings table
product_monthly_report_enabled = '1'
product_monthly_report_email = 'admin@example.com'
```

---

## 🎯 Key Features

### 1. **Gym-Specific Tracking**
- Every sale is tracked by `gym_identifier`
- Statistics automatically filtered by logged-in gym
- Each gym sees only their own sales data

### 2. **Inventory Management**
- Real-time quantity tracking
- `quantity_left` = `quantity` - `total_sold`
- Low stock alerts
- Automatic validation before sales

### 3. **Role-Based Permissions**
```
Super Admin:
  ✅ Add products
  ✅ Update products (including quantity)
  ✅ Delete products
  ✅ Record sales

Admin:
  ❌ Cannot add/update/delete products
  ✅ Can only record sales
```

### 4. **Comprehensive Analytics**
- Monthly, weekly, and custom period stats
- Product-wise breakdown
- Revenue and unit tracking
- Top-selling products
- Growth trends

### 5. **Multi-Image Support**
- Store multiple image URLs per product
- JSON array format
- Easy gallery implementation

---

## 🚨 Error Responses

### 400 Bad Request
```json
{
  "code": "invalid_quantity",
  "message": "Valid quantity is required.",
  "data": {
    "status": 400
  }
}
```

### 403 Forbidden
```json
{
  "code": "forbidden",
  "message": "Super admin access required.",
  "data": {
    "status": 403
  }
}
```

### 404 Not Found
```json
{
  "code": "product_not_found",
  "message": "Product not found.",
  "data": {
    "status": 404
  }
}
```

### 409 Conflict
```json
{
  "code": "duplicate_sku",
  "message": "Product with this SKU already exists.",
  "data": {
    "status": 409
  }
}
```

---

## 📝 Usage Examples

### Example 1: Record a Sale
```javascript
// Admin selling 5 protein shakes
const response = await fetch('/wp-json/gym-admin/v1/products/1/sale', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    quantity: 5,
    note: 'Sold to member #123 - Card payment'
  })
});

const data = await response.json();
console.log(data);
// Product quantity automatically reduced
// Sale tracked under current gym (afrgym_one or afrgym_two)
```

### Example 2: Check Monthly Performance
```javascript
// Get this month's statistics
const stats = await fetch('/wp-json/gym-admin/v1/products/stats/monthly', {
  headers: {
    'Authorization': 'Bearer YOUR_TOKEN'
  }
});

const data = await stats.json();
console.log(`Total Revenue: ₦${data.data.summary.total_revenue}`);
console.log(`Units Sold: ${data.data.summary.total_units_sold}`);
```

### Example 3: Update Inventory (Super Admin)
```javascript
// Restock a product
const update = await fetch('/wp-json/gym-admin/v1/products/1', {
  method: 'PUT',
  headers: {
    'Authorization': 'Bearer SUPER_ADMIN_TOKEN',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    quantity: 200  // Set new total quantity
  })
});
```

---

## ✅ Implementation Checklist

- [x] Create 3 product-related database tables
- [x] Add product CRUD endpoints (11 total)
- [x] Implement gym-specific tracking
- [x] Add role-based permissions
- [x] Create statistics endpoints
- [x] Build monthly report system
- [x] Add email template for reports
- [x] Implement low stock alerts
- [x] Add activity logging
- [x] Update plugin activation
- [x] Schedule automated reports

---

## 🔧 Configuration

### Enable/Disable Monthly Reports
```php
// Via database
UPDATE wp_gym_settings 
SET setting_value = '1'  -- '1' = enabled, '0' = disabled
WHERE setting_name = 'product_monthly_report_enabled';
```

### Change Report Email
```php
UPDATE wp_gym_settings 
SET setting_value = 'newemail@example.com'
WHERE setting_name = 'product_monthly_report_email';
```

### Adjust Low Stock Threshold
```php
UPDATE wp_gym_settings 
SET setting_value = '15'
WHERE setting_name = 'product_low_stock_threshold';
```

---

## 📞 Support

For issues or questions:
1. Check error logs in WordPress debug
2. Verify JWT token is valid
3. Confirm user has correct permissions
4. Check database table integrity

---

**Version:** 5.1.0  
**Last Updated:** January 2025  
**Total Endpoints:** 52 (11 new product endpoints added)