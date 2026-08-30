# Vercel Deployment Guide for icut

## Prerequisites
- Vercel account (free tier available)
- TiDB Cloud database (already configured)
- Vercel CLI installed (`npm i -g vercel`)

## Environment Variables

Set these in Vercel Dashboard → Settings → Environment Variables:

### Required
- `MYSQL_HOST` - Your TiDB Cloud host
- `MYSQL_PORT` - Your TiDB Cloud port (usually 4000)
- `MYSQL_NAME` - Database name
- `MYSQL_USER` - Database username
- `MYSQL_PASS` - Database password
- `MYSQL_CHARSET` - utf8mb4
- `MYSQL_SSL` - 1 (for TiDB Cloud)
- `JWT_SECRET` - Random secret key for JWT tokens (generate with `openssl rand -hex 32`)
- `ADMIN_ENTRY_KEY` - Secret key for admin access (e.g., `icitboss`)
- `SITE_URL` - Your Vercel deployment URL

### Optional
- `BLOB_READ_WRITE_TOKEN` - Vercel Blob token for file uploads
- `JWT_COOKIE_NAME` - Cookie name (default: `icut_session`)
- `JWT_EXPIRY` - Token expiry in seconds (default: 86400 = 24 hours)

## Deployment Steps

1. Install Vercel CLI:
```bash
npm i -g vercel
```

2. Login to Vercel:
```bash
vercel login
```

3. Deploy:
```bash
vercel --prod
```

4. Set environment variables in Vercel dashboard

5. Redeploy after setting env vars:
```bash
vercel --prod
```

## Local Development

```bash
vercel dev
```

This will start a local server with hot reloading.

## Architecture

### API Routes
- `GET /api/index` - Main booking page HTML
- `POST /api/book` - Create booking
- `POST /api/admin-login` - Admin login (returns JWT)
- `GET /api/admin` - Admin dashboard (requires JWT)
- `POST /api/admin-logout` - Admin logout
- `GET /api/client-portal` - Client portal
- `POST /api/cancel-booking` - Cancel booking
- `GET /api/business-hours` - Get business hours
- `POST /api/business-hours` - Update business hours
- `GET /api/print-sheet` - Print daily schedule
- `GET /api/client-history` - Get client history
- `POST /api/client-history` - Update client notes
- `GET /api/export-bookings` - Export bookings CSV
- `GET /api/change-password` - Get change password form
- `POST /api/change-password` - Update password

### Frontend
The frontend makes AJAX calls to the API routes instead of traditional form submissions.

### Sessions
Uses JWT tokens stored in HTTP-only cookies instead of PHP sessions.

### File Uploads
Uses Vercel Blob for file uploads (configure `BLOB_READ_WRITE_TOKEN`).

## Notes

- All API routes return JSON responses
- The frontend should be updated to use fetch() API for form submissions
- Static assets (CSS, JS, images) should be in the `public/` directory
- Vercel Blob is used for file uploads instead of local filesystem
