# OTP Chat Refactor

A modern, production-ready PHP web application for OTP (One-Time Password) based user registration and authentication using SMS verification. Built with clean architecture principles, comprehensive testing, and Facebook Pixel integration for conversion tracking.

## 🔋 Features

- ✅ **OTP-based Authentication** - Secure SMS-based verification via Ideamart, Mspace, and bdapps platforms
- ✅ **Multi-Carrier Support** - Support for Dialog, Hutch, Airtel, and Mobitel (Sri Lanka) + Robi, Airtel (Bangladesh)
- ✅ **Facebook CAPI Integration** - Server-side conversion tracking with event deduplication
- ✅ **Rate Limiting** - Built-in protection against abuse
- ✅ **CSRF Protection** - Secure form submissions
- ✅ **Comprehensive Logging** - JSON-formatted rotating logs with Monolog
- ✅ **User Tracking** - Visitor identification and analytics
- ✅ **95%+ Test Coverage** - Unit and acceptance tests with PHPUnit and Codeception
- ✅ **Modern PHP** - PHP 8.2+ with strict types and best practices

## 📋 Requirements

- **PHP** 8.2 or higher
- **Composer** 2.x
- **SQLite3** extension enabled
- **cURL** extension enabled
- **Xdebug** (optional, for code coverage)
- **Microsoft Edge WebDriver** (for acceptance tests)

## 🚀 Installation

### 1. Clone the Repository

```bash
git clone https://github.com/cbarua19/otp-chat-refactor.git
cd otp-chat-refactor
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Configure Environment

Copy the sample environment file and configure it:

```bash
copy .env.sample .env
```

Edit `.env` and configure:

```env
# Application
APP_ENV=development  # or 'production'

# Paths (must be outside public web root)
APP_LOG_DIR="../logs/app"
CAPI_LOG_DIR="../logs/capi"
ERROR_LOG_PATH="../logs/error.log"
DB_PATH="../logs/userlog.sqlite"

# OTP API URLs
IDEAMART_URLS='["https://your-api.com/ideamart/"]'
MSPACE_URLS='["https://your-api.com/mspace/"]'
BDAPPS_URLS='["https://your-api.com/bdapps/"]'

# Facebook Pixel & CAPI
PIXEL_ID=YOUR_PIXEL_ID
FBCAPI_TOKEN=YOUR_CAPI_ACCESS_TOKEN
TEST_EVENT_CODE=TEST12345  # Optional, for testing

# Content
IMG_URL="assets/images/your-image.jpeg"
IMG_ALT="Your image description"
CHARGE_TEXT="Daily Rs 10+tax"
```

### 4. Create Required Directories

```bash
mkdir -p logs/app logs/capi public/assets/images
```

### 5. Configure Web Server

#### Apache (.htaccess already included)

Point your document root to the `public/` directory:

```apache
DocumentRoot "C:/xampp/htdocs/otp-chat-refactor/public"
```

#### Virtual Host Example

```apache
<VirtualHost *:80>
    ServerName otp-chat.test
    DocumentRoot "C:/xampp/htdocs/otp-chat-refactor/public"
    
    <Directory "C:/xampp/htdocs/otp-chat-refactor/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Add to your hosts file:
```
127.0.0.1 otp-chat.test
```

## 🧪 Testing

### Unit Tests

Run PHPUnit tests with coverage:

```bash
# Run all unit tests
composer test:unit

# With code coverage
composer test:coverage
```

**Current Coverage:** 95.43% line coverage (647 / 678 lines)

### Acceptance Tests

Acceptance tests use Codeception with Microsoft Edge WebDriver.

#### Setup WebDriver

1. Download [Microsoft Edge WebDriver](https://developer.microsoft.com/en-us/microsoft-edge/tools/webdriver/)
2. Extract to `C:\webdriver\msedgedriver.exe`

#### Run Tests

```bash
# Start WebDriver (in separate terminal)
composer dev:edgedriver

# Run acceptance tests (in another terminal)
composer test:acceptance

# Stop WebDriver when done
composer test:stop
```

### Test Structure

```
tests/
├── Acceptance/              # Browser-based end-to-end tests
│   └── RegistrationFlowCest.php
├── Unit/                    # PHPUnit unit tests
│   ├── *ControllerTest.php
│   ├── *ServiceTest.php
│   └── ValidatorTest.php
└── Support/                 # Test helpers
```

## 📁 Project Structure

```
otp-chat-refactor/
├── bootstrap/
│   └── app.php              # Application bootstrap & DI container
├── config/
│   ├── app.php              # Main configuration
│   └── carriers.php         # Mobile carrier configuration
├── public/
│   ├── index.php            # Front controller
│   └── assets/              # Public assets (CSS, JS, images)
├── routes/
│   └── web.php              # Route definitions
├── src/
│   ├── Controller/          # MVC Controllers
│   ├── Service/             # Business logic services
│   └── Utils/               # Utility classes
├── templates/               # View templates (.php)
├── tests/                   # Test suite
└── logs/                    # Application logs (excluded from git)
```

## 🏗️ Architecture

### Design Patterns

- **MVC Architecture** - Controllers, services, and views separation
- **Dependency Injection** - Pimple DI container
- **Repository Pattern** - OTP API abstraction
- **Service Layer** - Business logic encapsulation
- **Front Controller** - Single entry point (public/index.php)
- **Singleton Pattern** - Shared resource instances (HTTP client, loggers)

### Key Components

#### Controllers
- `FormController` - Phone number form handling
- `OtpController` - OTP verification
- `ThankYouController` - Success page

#### Services
- `OtpApiService` - OTP API integration with failover
- `RateLimiterService` - SQLite-based rate limiting
- `CsrfService` - CSRF token management
- `FacebookCapiService` - Server-side event tracking
- `UserLoggerService` - User visit tracking
- `SessionService` - Session management wrapper

#### Utilities
- `Validator` - Phone number and OTP validation
- `UserInfoService` - Device and IP detection

## 🔧 Configuration

### Carrier Configuration

Edit `config/carriers.php` to add/modify mobile carriers:

```php
'LK' => [ // Sri Lanka
    '77' => ['carrier' => 'Dialog', 'platform' => 'ideamart', ...],
    '71' => ['carrier' => 'Mobitel', 'platform' => 'mspace', ...],
],
```

### Guzzle HTTP Client

The Guzzle client is configured with:
- **Timeout:** 30 seconds (total request time)
- **Connect Timeout:** 10 seconds (SSL handshake time)

Adjust in `bootstrap/app.php` if needed.

## 📊 Logging

### Log Locations

- **Application Logs:** `logs/app/app-YYYY-MM-DD.log`
- **CAPI Logs:** `logs/capi/capi-YYYY-MM-DD.log`
- **PHP Errors:** `logs/error.log`

### Log Format

JSON-formatted logs with Monolog:

```json
{
  "message": "OTP request successful",
  "context": {
    "phone": "94771234567",
    "platform": "ideamart"
  },
  "level": 200,
  "level_name": "INFO",
  "channel": "app",
  "datetime": "2025-11-23T14:30:00+05:30",
  "extra": {
    "session_id": "abc123..."
  }
}
```

## 🔒 Security Features

- ✅ **CSRF Protection** - All forms protected with CSRF tokens
- ✅ **Rate Limiting** - IP-based and session-based limits
- ✅ **Input Validation** - Strict phone and OTP format validation
- ✅ **Session Security** - HttpOnly, Secure, SameSite cookies
- ✅ **SQL Injection Prevention** - Prepared statements
- ✅ **XSS Prevention** - Output escaping in templates

## 📈 Facebook Conversion Tracking

### Client-Side Pixel

The app fires Facebook Pixel events for:
- `PageView` - On all pages
- `Lead` - When OTP form is submitted with a valid phone number
- `CompleteRegistration` - On successful verification

### Server-Side CAPI

All pixel events are duplicated server-side via Facebook CAPI for:
- Ad-blocker resilience
- iOS 14+ tracking
- Event deduplication with `eventID`

## 🐛 Debugging

### Enable Debug Mode

Set in `.env`:
```env
APP_ENV=development
```

### View Logs

```bash
# Tail application logs
tail -f logs/app/app-YYYY-MM-DD.log

# View errors
tail -f logs/error.log
```

### Clear Rate Limits

```php
// In tests or debugging
$db = new SQLite3('logs/userlog.sqlite');
$db->exec("DELETE FROM rate_limits");
```

## 📝 Development Scripts

```bash
# Run unit tests with testdox
composer test:unit

# Generate coverage report
composer test:coverage

# Run acceptance tests
composer test:acceptance

# Start Edge WebDriver
composer dev:edgedriver

# Stop Edge WebDriver
composer test:stop
```

## 🔄 Common Tasks

### Adding a New Carrier

1. Edit `config/carriers.php`
2. Add carrier configuration
3. Update `.env` with API URLs if needed
4. Test with the carrier's phone number format

### Modifying Timeouts

Edit `bootstrap/app.php`:

```php
$container['GuzzleClient'] = function ($c) {
    return new Client([
        'timeout' => 30,         // Change here
        'connect_timeout' => 10, // Change here
        // ...
    ]);
};
```

## 🚢 Deployment

### Production Checklist

- [ ] Set `APP_ENV=production` in `.env`
- [ ] Configure proper log paths outside web root
- [ ] Set strong Facebook CAPI token
- [ ] Remove `TEST_EVENT_CODE` from `.env`
- [ ] Enable HTTPS and set `Secure` cookies
- [ ] Set up log rotation (Monolog handles file rotation automatically)
- [ ] Configure proper database backups for SQLite
- [ ] Review and adjust rate limits
- [ ] Test OTP APIs in production
- [ ] Monitor error logs

