# WooCommerce Update API

**Version:** 1.1.3  
**Requires:** WordPress 5.8+, WooCommerce 5.0+, PHP 8.0+  
**License:** GPL v2 or later  

## Description

WooCommerce Update API is a powerful plugin that connects your WooCommerce store to external APIs to fetch real-time product pricing and inventory data. Perfect for stores that need to sync with supplier systems, ERP software, or custom inventory management solutions.

## Features

- **Real-time API Integration**: Connect to any REST API for product data
- **Smart Caching**: Configurable cache duration to balance performance and freshness
- **Fallback Mode**: Automatically switches to WooCommerce defaults if API fails
- **Manual Refresh**: Update individual products on demand
- **Connection Monitoring**: Real-time status display with error tracking
- **Bulk Operations**: Refresh multiple products at once
- **Email Notifications**: Get alerted when API fails

## Installation

1. Upload the `woo-update-api` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings → WC Update API to configure your API connection

## Configuration

### Required Settings

1. **API Endpoint URL**: The full URL to your product API endpoint
2. **API Key**: Your authentication key for the API
3. **Cache Duration**: How long to cache API responses (recommended: 300 seconds)

### API Response Format

Your API should return JSON in one of these formats:

```json
{
  "product": {
    "price_mxn": 199.99,
    "stock_quantity": 42,
    "in_stock": true
  }
}
