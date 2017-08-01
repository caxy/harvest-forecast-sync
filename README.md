# Harvest -> Forecast Sync
Command to sync data from harvest into forecast

## Setup

```bash
# Clone the repo and cd into the directory
$ git clone git@github.com:caxy/php-forecast-api.git
$ cd php-forecast-api

# Install dependencies
$ composer install

# Create config.php file
$ cp config.php.dist config.php
```

### Configuration

Edit the config.php file and enter your harvest credentials and your forecast credentials.

```php
// config.php

return array(
    'harvest' => array(
        'account' => 'caxyinteractive',
        'user' => '',
        'pass' => '',
    ),
    'forecast' => array(
        'account' => 792946,
        'auth' => '',
    ),
);
```

To determine your accountId and authorization token, log in to Forecast and use the web inspector > Network tab to see one of the requests being made to api.forecastapp.com.
 
Note the accoundId and authorization from the request headers.

The `auth` config value should be set to the value of the `authorization` header, *without* the `Bearer ` text at the beginning.

## Usage

There's just one command:

```bash
$ php bin/console app:sync [<from>] [<to>]
```

The `from` and `to` arguments are optional, and it will default to use the current month if they are not passed.

#### Sync current month's data

```bash
$ php bin/console app:sync
```

#### Sync data starting from specific date until today's date

```bash
$ php bin/console app:sync "2017-01-01"
```

#### Sync data from specific date range

```bash
$ php bin/console app:sync "2017-01-01" "2017-06-01"
```
