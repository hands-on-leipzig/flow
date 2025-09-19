<?php

/**
 * Slug Handler for Event Routing
 * 
 * This script handles requests to domain/slug and redirects them to
 * the appropriate zeitplan.cgi with the correct plan parameter.
 */

// Bootstrap Laravel
require_once __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Event;
use App\Models\Plan;

// Get the slug from the URL
$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    http_response_code(404);
    echo "Slug not provided";
    exit;
}

try {
    // Find the event by slug
    $event = Event::where('slug', $slug)->first();
    
    if (!$event) {
        http_response_code(404);
        echo "Event not found for slug: " . htmlspecialchars($slug);
        exit;
    }
    
    // Get the plan for this event
    $plan = Plan::where('event', $event->id)->first();
    
    if (!$plan) {
        http_response_code(404);
        echo "No plan found for event: " . htmlspecialchars($event->name);
        exit;
    }
    
    // Build the redirect URL to zeitplan.cgi
    $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $baseUrl = rtrim($scheme . '://' . $host, '/');
    $redirectUrl = $baseUrl . '/output/zeitplan.cgi';
    
    // Add the plan parameter
    $redirectUrl .= '?plan=' . $plan->id;
    
    // Preserve any additional query parameters
    $queryParams = $_GET;
    unset($queryParams['slug']); // Remove the slug parameter
    
    if (!empty($queryParams)) {
        $redirectUrl .= '&' . http_build_query($queryParams);
    }
    
    // Redirect to zeitplan.cgi
    header('Location: ' . $redirectUrl);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    echo "Error: " . htmlspecialchars($e->getMessage());
    exit;
}
