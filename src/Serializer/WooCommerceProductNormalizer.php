<?php

namespace App\Serializer;

use App\Entity\Productos;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class WooCommerceProductNormalizer implements NormalizerInterface
{
    private $normalizer;
    
    public function __construct(ObjectNormalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }
    
    public function normalize($object, string $format = null, array $context = [])
    {
        // Default normalization
        $data = $this->normalizer->normalize($object, $format, $context);
        
        // Transform to WooCommerce format
        $wooCommerceData = [
            // Basic product info
            'id' => $data['id'] ?? null,
            'sku' => $data['sku'] ?? $data['codigo'] ?? null,
            'name' => $data['nombre'] ?? $data['name'] ?? null,
            
            // Pricing (in MXN)
            'price_mxn' => $this->extractPrice($data),
            'regular_price' => $this->extractRegularPrice($data),
            'sale_price' => $this->extractSalePrice($data),
            
            // Stock information
            'stock_quantity' => $this->extractStockQuantity($data),
            'in_stock' => $this->extractStockStatus($data),
            
            // Product details
            'description' => $data['descripcion'] ?? $data['description'] ?? null,
            'short_description' => $data['resumen'] ?? $data['short_description'] ?? null,
            
            // Dimensions and weight if available
            'weight_kg' => $data['peso'] ?? $data['weight'] ?? null,
            'length_cm' => $data['largo'] ?? $data['length'] ?? null,
            'width_cm' => $data['ancho'] ?? $data['width'] ?? null,
            'height_cm' => $data['alto'] ?? $data['height'] ?? null,
            
            // Additional metadata
            'manufacturer' => $data['fabricante'] ?? $data['manufacturer'] ?? null,
            'category' => $this->extractCategory($data),
            'tags' => $this->extractTags($data),
            
            // URLs
            'image_url' => $this->extractImageUrl($data),
            'product_url' => $this->generateProductUrl($data),
        ];
        
        // Remove null values
        return array_filter($wooCommerceData, function($value) {
            return $value !== null;
        });
    }
    
    public function supportsNormalization($data, string $format = null): bool
    {
        return $data instanceof Productos && $format === 'woocommerce';
    }
    
    private function extractPrice(array $data): ?float
    {
        $price = $data['precio_actual'] ?? $data['precio'] ?? $data['price'] ?? null;
        
        if ($price !== null) {
            return (float) $price;
        }
        
        return null;
    }
    
    private function extractRegularPrice(array $data): ?float
    {
        $price = $data['precio_regular'] ?? $data['precio_lista'] ?? $data['regular_price'] ?? null;
        
        if ($price !== null) {
            return (float) $price;
        }
        
        // Fallback to current price
        return $this->extractPrice($data);
    }
    
    private function extractSalePrice(array $data): ?float
    {
        $price = $data['precio_oferta'] ?? $data['sale_price'] ?? null;
        
        if ($price !== null) {
            return (float) $price;
        }
        
        return null;
    }
    
    private function extractStockQuantity(array $data): ?int
    {
        $stock = $data['stock'] ?? $data['inventario'] ?? $data['quantity'] ?? null;
        
        if ($stock !== null) {
            return (int) $stock;
        }
        
        return 0;
    }
    
    private function extractStockStatus(array $data): bool
    {
        $quantity = $this->extractStockQuantity($data);
        $status = $data['disponible'] ?? $data['available'] ?? null;
        
        if ($status !== null) {
            return (bool) $status;
        }
        
        return $quantity > 0;
    }
    
    private function extractCategory(array $data): ?array
    {
        $category = $data['categoria'] ?? $data['category'] ?? null;
        
        if (!$category) {
            return null;
        }
        
        return [
            'id' => $category['id'] ?? null,
            'name' => $category['nombre'] ?? $category['name'] ?? null,
            'slug' => $this->createSlug($category['nombre'] ?? $category['name'] ?? '')
        ];
    }
    
    private function extractTags(array $data): array
    {
        $tags = $data['etiquetas'] ?? $data['tags'] ?? [];
        
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }
        
        return array_map('trim', (array) $tags);
    }
    
    private function extractImageUrl(array $data): ?string
    {
        $image = $data['imagen_principal'] ?? $data['imagen'] ?? $data['image'] ?? null;
        
        if (is_array($image)) {
            return $image['url'] ?? $image['path'] ?? null;
        }
        
        return $image;
    }
    
    private function generateProductUrl(array $data): ?string
    {
        $id = $data['id'] ?? null;
        $slug = $this->createSlug($data['nombre'] ?? $data['name'] ?? '');
        
        if ($id && $slug) {
            return sprintf('/producto/%s-%d', $slug, $id);
        }
        
        return null;
    }
    
    private function createSlug(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        
        return $text ?: 'product';
    }
}
