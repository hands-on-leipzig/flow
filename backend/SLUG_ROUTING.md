# Event Slug Routing System

This system allows users to access event schedules using friendly URLs like `domain/slug` instead of complex URLs with plan IDs.

## 🎯 How It Works

### 1. URL Pattern
- **Input**: `https://domain.com/my-event-slug`
- **Output**: Redirects to `https://domain.com/output/zeitplan.cgi?plan=123`

### 2. Routing Flow
```
User visits: domain.com/my-event-slug
     ↓
.htaccess catches the request
     ↓
Redirects to: /slug-handler.php?slug=my-event-slug
     ↓
slug-handler.php looks up event by slug
     ↓
Finds the plan for that event
     ↓
Redirects to: /output/zeitplan.cgi?plan=123
     ↓
zeitplan.cgi displays the schedule
```

## 🔧 Implementation Details

### Files Modified/Created

1. **`.htaccess`** - Main routing rules
2. **`backend/public/slug-handler.php`** - Slug lookup and redirect logic

### .htaccess Rules

```apache
# 🎯 Event slug routing - redirect to slug handler
# Pattern: /slug -> /slug-handler.php?slug=slug
RewriteCond %{REQUEST_URI} !^/(api|sanctum|login|logout|password|output|legacy)/
RewriteCond %{REQUEST_URI} ^/([a-zA-Z0-9_-]+)/?$
RewriteRule ^([a-zA-Z0-9_-]+)/?$ /slug-handler.php?slug=$1 [L,QSA]
```

### slug-handler.php Logic

1. **Extract slug** from `$_GET['slug']`
2. **Find event** by slug in database
3. **Get plan** for that event
4. **Build redirect URL** to `zeitplan.cgi`
5. **Preserve query parameters** (if any)
6. **Redirect** to the final URL

## 📋 Requirements

### Database Structure
- **Event table** must have a `slug` column
- **Plan table** must be linked to events via `event` foreign key
- **Each event** should have at least one plan

### Example Data
```sql
-- Event with slug
INSERT INTO event (name, slug, ...) VALUES ('My Event', 'my-event-slug', ...);

-- Plan for that event
INSERT INTO plan (event, name, ...) VALUES (1, 'Zeitplan', ...);
```

## 🧪 Testing

### Test URLs
- `https://domain.com/test-region-a-explore` → Should redirect to zeitplan.cgi
- `https://domain.com/non-existent-slug` → Should show 404 error
- `https://domain.com/test-region-a-explore?role=14` → Should preserve role parameter

### Manual Testing
```bash
# Test slug handler directly
php -r "\$_GET['slug'] = 'test-region-a-explore'; \$_SERVER['REQUEST_SCHEME'] = 'http'; \$_SERVER['HTTP_HOST'] = 'localhost'; include 'backend/public/slug-handler.php';"
```

## 🚨 Error Handling

### 404 Errors
- **Slug not provided**: "Slug not provided"
- **Event not found**: "Event not found for slug: {slug}"
- **No plan found**: "No plan found for event: {event_name}"

### 500 Errors
- **Database errors**: Shows the actual error message
- **Laravel bootstrap errors**: Shows the exception message

## 🔒 Security Considerations

### Input Validation
- **Slug format**: Only alphanumeric characters, hyphens, and underscores allowed
- **SQL injection**: Protected by Laravel's Eloquent ORM
- **XSS protection**: All output is escaped with `htmlspecialchars()`

### Access Control
- **No authentication required**: Slugs are public URLs
- **No sensitive data**: Only redirects to public schedule pages
- **Rate limiting**: Consider adding rate limiting for production

## 🚀 Deployment

### Production Setup
1. **Ensure .htaccess** is in the web root
2. **Deploy slug-handler.php** to `public/` directory
3. **Test with real slugs** from your database
4. **Monitor error logs** for any issues

### Environment Variables
- **REQUEST_SCHEME**: Automatically set by web server
- **HTTP_HOST**: Automatically set by web server
- **Database connection**: Handled by Laravel configuration

## 📝 Usage Examples

### Creating Event Slugs
```php
// In your event creation/update code
$event = Event::create([
    'name' => 'FLL Regional Event Augsburg',
    'slug' => 'fll-augsburg-2024',
    // ... other fields
]);
```

### Accessing Schedules
- **Public URL**: `https://flow.hands-on-technology.org/fll-augsburg-2024`
- **With parameters**: `https://flow.hands-on-technology.org/fll-augsburg-2024?role=14&brief=yes`

## 🔧 Troubleshooting

### Common Issues

1. **404 on valid slug**
   - Check if event exists in database
   - Verify slug is correct
   - Check if event has a plan

2. **Redirect loops**
   - Check .htaccess rules
   - Verify slug-handler.php is accessible
   - Check for conflicting rewrite rules

3. **Database errors**
   - Verify Laravel configuration
   - Check database connection
   - Ensure models are properly configured

### Debug Mode
Add this to slug-handler.php for debugging:
```php
// Add at the top for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## 📈 Performance

### Optimization Tips
- **Database indexing**: Add index on `event.slug` column
- **Caching**: Consider caching slug-to-plan mappings
- **CDN**: Use CDN for static assets in zeitplan.cgi output

### Monitoring
- **Error rates**: Monitor 404/500 error rates
- **Response times**: Track redirect performance
- **Database queries**: Monitor query performance
