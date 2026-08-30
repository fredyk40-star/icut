# Vercel Serverless Migration Guide

## What Changed

The icut app has been converted from traditional PHP to **Vercel Serverless Functions**. Each PHP file in the `/api` directory becomes a serverless endpoint.

## Key Differences

| Traditional PHP | Vercel Serverless |
|----------------|-------------------|
| `$_SESSION` | JWT tokens in HTTP-only cookies |
| Local file uploads | Vercel Blob storage |
| Form POST → page reload | AJAX POST → JSON response |
| Persistent filesystem | Ephemeral (no local file writes) |
| `require_once 'db.php'` | `require_once __DIR__ . '/../lib/db.php'` |

## API Routes

### Public Routes (no auth required)
- `GET /api/index` - Main booking page (returns HTML)
- `POST /api/admin-login` - Admin login (returns JWT)
- `GET /api/client-portal` - Client portal (returns HTML)
- `POST /api/client-portal` - Lookup booking (returns JSON)
- `POST /api/cancel-booking` - Cancel booking (returns JSON)

### Protected Routes (require JWT)
- `GET /api/admin` - Admin dashboard (JSON)
- `POST /api/admin-logout` - Logout
- `GET /api/business-hours` - Business hours settings
- `POST /api/business-hours` - Update business hours
- `GET /api/print-sheet` - Print daily schedule (HTML)
- `GET /api/client-history` - Client history
- `POST /api/client-history` - Update client notes
- `GET /api/export-bookings` - Export CSV
- `GET /api/change-password` - Change password form
- `POST /api/change-password` - Update password

## Frontend Changes Required

The frontend needs to be updated to use AJAX instead of traditional form submissions.

### Example: Booking Form (index.php)

**Before (traditional PHP):**
```html
<form method="POST" action="#book">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <!-- form fields -->
    <button type="submit">Book Now</button>
</form>
```

**After (AJAX for Vercel):**
```html
<form id="booking-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <!-- form fields -->
    <button type="submit">Book Now</button>
</form>

<script>
document.getElementById('booking-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const response = await fetch('/api/index', {
        method: 'POST',
        body: formData
    });
    
    const result = await response.json();
    if (result.success) {
        alert('Booking confirmed!');
    } else {
        alert(result.error);
    }
});
</script>
```

## Deployment Steps

1. **Install Vercel CLI:**
```bash
npm i -g vercel
```

2. **Login:**
```bash
vercel login
```

3. **Deploy:**
```bash
vercel --prod
```

4. **Set Environment Variables in Vercel Dashboard:**
   - `MYSQL_HOST` - Your TiDB Cloud host
   - `MYSQL_PORT` - 4000
   - `MYSQL_NAME` - barbershop_db
   - `MYSQL_USER` - Your TiDB username
   - `MYSQL_PASS` - Your TiDB password
   - `MYSQL_CHARSET` - utf8mb4
   - `MYSQL_SSL` - 1
   - `JWT_SECRET` - Generate with `openssl rand -hex 32`
   - `ADMIN_ENTRY_KEY` - Secret admin access key
   - `SITE_URL` - Your Vercel domain
   - `BLOB_READ_WRITE_TOKEN` - Get from Vercel Blob dashboard

5. **Initialize Vercel Blob:**
```bash
vercel blob create icut-uploads
```

6. **Redeploy after setting env vars:**
```bash
vercel --prod
```

## Testing Locally

```bash
vercel dev
```

This starts a local server with hot reloading. Your app will be available at `http://localhost:3000`.

## Important Notes

1. **File Uploads**: Must use Vercel Blob. Local `uploads/` directory won't work in serverless.
2. **Sessions**: All `$_SESSION` usage replaced with JWT cookies.
3. **Database**: TiDB Cloud connection already works (tested).
4. **CSRF**: Still uses session-based tokens (works in serverless).
5. **Rate Limiting**: Uses database (already MySQL-compatible).

## Cost

- **Vercel Hobby (Free)**: $0/month
  - 100GB bandwidth/month
  - Unlimited serverless functions
  - Vercel Blob: 5GB storage free
- **TiDB Cloud**: Free tier available (check their pricing)

## Support

For issues:
1. Check Vercel function logs in dashboard
2. Verify environment variables are set
3. Test database connection with `/test_db.php`
