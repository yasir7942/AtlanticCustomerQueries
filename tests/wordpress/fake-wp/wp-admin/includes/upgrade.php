<?php

// Test stand-in for WordPress' dbDelta(): runs the plugin's CREATE TABLE as-is against MariaDB.
function dbDelta($sql)
{
    global $wpdb;
    $wpdb->query($sql);
    return array();
}
