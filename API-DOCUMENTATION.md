# Elmonofy ERP Integration — API Documentation

**Plugin Version:** 5.1.0  
**Last Updated:** February 20, 2026  
**Namespace:** `elmonofy/v1`

---

## Table of Contents

- [Overview](#overview)
- [Authentication](#authentication)
- [Outgoing Sync (WooCommerce → ERP)](#outgoing-sync-woocommerce--erp)
- [Incoming API (ERP → WooCommerce)](#incoming-api-erp--woocommerce)
  - [Update Stock & Price](#1-update-stock--price)
  - [Create Product](#2-create-product)
  - [Get All Products (List)](#3-get-all-products-list)
  - [Get Product by SKU](#4-get-product-by-sku)
  - [Delete Product by SKU](#5-delete-product-by-sku)
- [Error Handling](#error-handling)
- [Retry Mechanism](#retry-mechanism)
- [Configuration](#configuration)

---

## Overview

The **Elmonofy ERP Integration** plugin provides a bidirectional sync layer between WooCommerce and Frappe-based ERP:

| Direction | Description |
|-----------|-------------|
| **Outgoing** | Automatically sends confirmed orders (status: `processing`) to the ERP webhook |
| **Incoming** | Exposes REST API endpoints for the ERP to manage WooCommerce products |

---

## Authentication

All REST API requests must include the following header:

```
Authorization: token <API_KEY>:<API_SECRET>
```

The token is configured in **WordPress Admin → Settings → Elmonofy ERP** and is validated using timing-safe comparison (`hash_equals`).

> [!IMPORTANT]
> Requests without a valid token will receive a `401 Unauthorized` response.

---

## Outgoing Sync (WooCommerce → ERP)

### Trigger

Orders are sent to the ERP **only** when they reach `processing` status. This covers:
- Online payment (auto-set to processing after successful payment)
- Cash on Delivery (set to processing on order confirmation)

### Endpoint Called

```
POST {ERP_BASE_URL}/api/method/woocommerce_integration.api.sales.process_order_webhook
```

### Payload Structure

```json
{
  "order": {
    "order_id": "1234",
    "status": "processing",
    "order_type": "Paid",
    "date_created": "2026-02-20 14:30:00",
    "currency": "EGP",
    "total": 1500.00,
    "subtotal": 1400.00,
    "total_tax": 0.00,
    "shipping_total": 100.00,
    "discount_total": 0.00,
    "payment_method": "Credit Card",
    "pickup_warehouse": "Market Place - EG",
    "line_items": [
      {
        "sku": "TSH-BLK-001",
        "name": "Black T-Shirt",
        "quantity": 2,
        "rate": 700.00,
        "total": 1400.00
      }
    ],
    "billing": {
      "first_name": "Ahmed",
      "last_name": "Mohamed",
      "address_1": "123 Tahrir St",
      "city": "Cairo",
      "country": "EG",
      "email": "ahmed@example.com",
      "phone": "+201234567890"
    },
    "shipping": {
      "first_name": "Ahmed",
      "last_name": "Mohamed",
      "address_1": "123 Tahrir St",
      "city": "Cairo",
      "country": "EG"
    }
  },
  "payment": {
    "payment_id": "txn_abc123",
    "amount": 1500.00,
    "payment_datetime": "2026-02-20 14:30:00"
  }
}
```

### Field Reference

| Field | Type | Description |
|-------|------|-------------|
| `order.order_id` | string | WooCommerce order ID |
| `order.status` | string | Always `"processing"` |
| `order.order_type` | string | `"Paid"` (online payment) or `"Pickup"` (COD) |
| `order.pickup_warehouse` | string | Configurable, default: `"Market Place - EG"` |
| `order.line_items[].rate` | float | Unit price the customer actually paid (after discounts) |
| `payment.payment_id` | string | Transaction ID from payment gateway, or `"woo_{order_id}"` if unavailable |
| `payment.payment_datetime` | string | Actual payment date, falls back to current time |

---

## Incoming API (ERP → WooCommerce)

**Base URL:** `https://your-site.com/wp-json/elmonofy/v1`

---

### 1. Update Stock & Price

Update an existing product's stock quantity and/or regular price.

```
POST /update-stock
```

**Request Body:**

```json
{
  "sku": "TSH-BLK-001",
  "stock_qty": 50,
  "price": "299.99"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `sku` | string | ✅ | Product SKU |
| `stock_qty` | integer | ❌ | New stock quantity (enables stock management) |
| `price` | string | ❌ | New regular price |

**Response (200):**

```json
{
  "status": "success",
  "id": 45,
  "sku": "TSH-BLK-001"
}
```

---

### 2. Create Product

Create a new simple product in WooCommerce.

```
POST /products
```

**Request Body:**

```json
{
  "name": "Black T-Shirt",
  "sku": "TSH-BLK-001",
  "price": "150.00",
  "stock_qty": 100
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | ✅ | Product name |
| `sku` | string | ✅ | Unique SKU |
| `price` | string | ❌ | Regular price |
| `stock_qty` | integer | ❌ | Initial stock quantity |

**Response (201):**

```json
{
  "status": "created",
  "id": 78,
  "sku": "TSH-BLK-001"
}
```

**Error — SKU already exists (409):**

```json
{
  "code": "sku_exists",
  "message": "A product with this SKU already exists.",
  "data": { "status": 409 }
}
```

---

### 3. Get All Products (List)

Retrieve a paginated list of all products.

```
GET /products?page=1&per_page=50
```

**Parameters (Query String):**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | integer | `1` | Page number |
| `per_page` | integer | `50` | Number of items per page |

**Response (200):**

```json
{
  "total": 1250,
  "max_pages": 25,
  "page": 1,
  "per_page": 50,
  "products": [
    {
      "id": 78,
      "name": "Black T-Shirt",
      "sku": "TSH-BLK-001",
      "price": 150.00,
      "stock_qty": 100,
      "status": "publish"
    }
  ]
}
```

---

### 4. Get Product by SKU

Retrieve product details by SKU.

```
GET /products/{sku}
```

**Example:**

```
GET /wp-json/elmonofy/v1/products/TSH-BLK-001
```

**Response (200):**

```json
{
  "id": 78,
  "name": "Black T-Shirt",
  "sku": "TSH-BLK-001",
  "price": 150.00,
  "stock_qty": 100,
  "status": "publish"
}
```

---

### 5. Delete Product by SKU

Move a product to the trash by SKU.

```
DELETE /products/{sku}
```

**Example:**

```
DELETE /wp-json/elmonofy/v1/products/TSH-BLK-001
```

**Response (200):**

```json
{
  "status": "trashed",
  "id": 78,
  "sku": "TSH-BLK-001"
}
```

---

## Error Handling

All endpoints return standard WordPress REST API error format:

```json
{
  "code": "error_code",
  "message": "Human-readable description",
  "data": { "status": 400 }
}
```

| HTTP Code | Meaning |
|-----------|---------|
| `200` | Success |
| `201` | Resource created |
| `400` | Bad request (missing required fields) |
| `401` | Unauthorized (invalid or missing token) |
| `404` | Product/SKU not found |
| `409` | Conflict (duplicate SKU) |

---

## Retry Mechanism

If the outgoing sync to the ERP fails, the plugin automatically retries:

| Attempt | Wait Time | Scheduled Via |
|---------|-----------|---------------|
| 1st retry | 15 minutes | Action Scheduler / WP Cron |
| 2nd retry | 30 minutes | Action Scheduler / WP Cron |
| 3rd retry | 45 minutes | Action Scheduler / WP Cron |

After 3 failed attempts, the order is marked as failed and requires manual retry from the admin panel.

**Admin can also trigger retries manually:**
- Individual: Order notes show sync status
- Bulk: Select orders → **Bulk Actions → Retry ERP Sync**

---

## Configuration

Navigate to **WordPress Admin → Settings → Elmonofy ERP**

| Setting | Description | Default |
|---------|-------------|---------|
| ERP Base URL | Frappe ERP instance URL | — |
| ERP Auth Token | API key:secret for authentication | — |
| Pickup Warehouse | Warehouse name sent in order payload | `Market Place - EG` |

### Logs

All sync activity is logged via WooCommerce Logger:

**WooCommerce → Status → Logs → Source: `elmonofy-erp`**

---

## Quick Reference

```
# Authentication Header (required for all requests)
Authorization: token <API_KEY>:<API_SECRET>

# Endpoints
POST   /wp-json/elmonofy/v1/update-stock        → Update stock & price
POST   /wp-json/elmonofy/v1/products             → Create product
GET    /wp-json/elmonofy/v1/products             → Get all products (list)
GET    /wp-json/elmonofy/v1/products/{sku}        → Get product
DELETE /wp-json/elmonofy/v1/products/{sku}        → Delete product
```
