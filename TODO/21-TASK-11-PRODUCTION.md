# Task 11: Production Preparation & Deployment

**Status:** Ready after all other tasks  
**Estimated Duration:** 2-3 hours  
**Difficulty:** Medium

---

## Objective

Prepare system for production deployment.

---

## Step 1: Environment Configuration

### Production .env Setup

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_HOST=production-db-host
DB_DATABASE=wallet_prod
DB_USERNAME=prod_user
DB_PASSWORD=strong_password

JWT_SECRET=generate-strong-secret

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

MAIL_DRIVER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=app-password

LOG_CHANNEL=stack
LOG_LEVEL=warning
```

---

## Step 2: Database Optimization

### Create Indexes

```sql
-- Frequently queried columns
CREATE INDEX idx_users_phone_number ON users(phone_number);
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_wallets_user_id ON wallets(user_id);
CREATE INDEX idx_transactions_user_id ON transactions(user_id);
CREATE INDEX idx_transactions_created_at ON transactions(created_at);
CREATE INDEX idx_user_roles_user_id ON user_roles(user_id);
CREATE INDEX idx_otp_tokens_user_id ON otp_tokens(user_id);
CREATE INDEX idx_otp_tokens_expires_at ON otp_tokens(expires_at);
```

### Connection Pooling

Use database connection pooling (e.g., PgBouncer for PostgreSQL).

---

## Step 3: Performance Optimization

### Cache Strategy

```
- Cache roles/permissions (1 hour)
- Cache settings (30 minutes)
- Cache user data (5 minutes)
- Don't cache: balances, transactions (real-time)
```

### Query Optimization

- Use eager loading (`with()`) to avoid N+1
- Index frequently filtered columns
- Profile slow queries with Laravel Debugbar

### Rate Limiting

- Auth endpoints: 5 requests per minute
- General endpoints: 60 requests per minute
- Transaction endpoints: 10 requests per minute

---

## Step 4: Security Hardening

### HTTPS Only
```php
// config/app.php
'url' => 'https://your-domain.com',

// Force HTTPS in production
if (env('APP_ENV') === 'production') {
    URL::forceScheme('https');
}
```

### CORS Configuration

```php
// config/cors.php
'allowed_origins' => [
    'https://your-domain.com',
    'https://admin.your-domain.com',
],
```

### API Keys

- Generate strong JWT secret
- Rotate keys periodically
- Never commit secrets to git

---

## Step 5: Logging & Monitoring

### Setup Logging

```
- Application logs: Sentry or similar
- Database queries: New Relic or Datadog
- API performance: CloudFlare or similar
```

### Error Tracking

- Install Sentry: `composer require sentry/sentry-laravel`
- Configure: `config/sentry.php`
- Test: `php artisan tinker` → `throw new Exception("test")`

### Monitoring

- Setup alerts for:
  - High error rates
  - Slow queries
  - High memory usage
  - Database connection issues

---

## Step 6: Deployment Pipeline

### GitHub Actions CI/CD

**File:** `.github/workflows/deploy.yml`

```yaml
name: Deploy

on:
  push:
    branches: [main]

jobs:
  test:
    runs-on: ubuntu-latest
    
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_DATABASE: test
          MYSQL_ROOT_PASSWORD: root
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3
        ports:
          - 3306:3306
    
    steps:
      - uses: actions/checkout@v2
      - uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2
      
      - run: composer install
      - run: php artisan migrate --env=testing
      - run: php artisan test
  
  deploy:
    needs: test
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v2
      
      # Deploy to production server
      - run: |
          mkdir -p ~/.ssh
          echo "${{ secrets.DEPLOY_KEY }}" > ~/.ssh/id_rsa
          chmod 600 ~/.ssh/id_rsa
          ssh-keyscan -H ${{ secrets.SERVER_HOST }} >> ~/.ssh/known_hosts
          
          ssh ${{ secrets.SERVER_USER }}@${{ secrets.SERVER_HOST }} << 'EOF'
            cd /var/www/wallet-system
            git pull origin main
            composer install --no-dev
            php artisan migrate --force
            php artisan cache:clear
            php artisan config:clear
            php artisan restart
          EOF
```

---

## Step 7: Database Migration Strategy

### Production Migration Checklist

Before migrating:
1. Backup database
2. Test migration on staging
3. Plan rollback strategy
4. Schedule during low-traffic window

### Running Migrations

```bash
php artisan migrate --force # Force in production
```

### Rollback Strategy

```bash
php artisan migrate:rollback --steps=1
```

---

## Step 8: Deployment Checklist

Before going live:

- [ ] All tests passing
- [ ] Environment variables configured
- [ ] Database indexes created
- [ ] Cache configured (Redis)
- [ ] Logging configured (Sentry)
- [ ] HTTPS enabled
- [ ] CORS configured
- [ ] Rate limiting enabled
- [ ] Backup strategy in place
- [ ] Monitoring alerts set up
- [ ] Load testing completed
- [ ] Security review completed
- [ ] Documentation updated
- [ ] Team trained on deployment

---

## Step 9: Post-Deployment

### Monitoring

Monitor for:
- Error rates
- Response times
- Database performance
- Memory usage
- Disk space

### Scaling

Plan for scaling:
- Database read replicas
- Redis cluster
- Load balancer
- CDN for static assets

### Updates

- Keep Laravel updated
- Keep dependencies updated
- Security patches immediately
- Regular backups

---

## Files to Create/Update

- `.github/workflows/deploy.yml` — CI/CD pipeline
- `config/database.php` — Connection pooling
- `config/logging.php` — Logging configuration
- `config/sentry.php` — Error tracking
- `docs/DEPLOYMENT.md` — Deployment guide
- `.env.production.example` — Production env template

---

## Checklist

- [ ] Production .env configured
- [ ] Database indexes created
- [ ] Cache strategy implemented
- [ ] HTTPS enabled
- [ ] Logging configured
- [ ] Monitoring alerts set
- [ ] CI/CD pipeline created
- [ ] Backup strategy documented
- [ ] Scaling plan created
- [ ] Team trained

---

## Deployment Complete! 🚀

Your wallet management system is now ready for production.
