<?php

/**
 * Deterministic local CI configuration. This file is mounted read-only by
 * docker/compose.ci-local.yml and is never used by production deployments.
 */
class Config
{
    const BASE_URL = 'http://localhost';
    const LANGUAGE = 'english';
    const DEBUG_MODE = false;
    const RATE_LIMITING = true;
    const WEBMCP_BOOKING_PILOT_ENABLED = false;
    const MATOMO_ANALYTICS_URL = '';
    const LDAP_HOST = 'openldap';
    const LDAP_PORT = 389;

    const DB_HOST = 'mysql';
    const DB_NAME = 'easyappointments';
    const DB_USERNAME = 'user';
    const DB_PASSWORD = 'password';
}
