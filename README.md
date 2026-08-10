# ServerAvatar Watchtower Agent

Official Laravel Agent for ServerAvatar Watchtower.

## Requirements

- PHP 8.2+
- Laravel 10.0+ or 11.0+

## Installation

1. Install the package via Composer:

```bash
composer require serveravatar/watchtower-agent
```

2. Configure your environment variables:

```env
WATCHTOWER_URL=https://watchtower.your-domain.com
WATCHTOWER_TOKEN=wt_live_your_token_here
```

3. (Optional) Publish the configuration file:

```bash
php artisan vendor:publish --tag=watchtower-config
```

## Configuration

### Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `WATCHTOWER_URL` | Your Watchtower installation URL | - |
| `WATCHTOWER_TOKEN` | Your project agent token | - |
| `WATCHTOWER_TIMEOUT` | Request timeout in seconds | `30` |
| `WATCHTOWER_CONNECT_TIMEOUT` | Connection timeout in seconds | `10` |
| `WATCHTOWER_RETRY_ENABLED` | Enable request retries | `true` |
| `WATCHTOWER_RETRY_ATTEMPTS` | Number of retry attempts | `3` |
| `WATCHTOWER_AGENT_ENABLED` | Enable/disable the agent | `true` |

## Usage

### Check Connection Status

```bash
php artisan watchtower:status
```

### Generate a Token

Generate a token through the Watchtower dashboard (Project → Agent → Generate Token).

## Support

For issues and feature requests, please visit [serveravatar.com](https://serveravatar.com).
