<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Values are overridden from .env (inquiry.apiUrl, inquiry.apiKey). Keep the key out of version control.
 */
class Inquiry extends BaseConfig
{
    public string $apiUrl = '';
    public string $apiKey = '';
}
