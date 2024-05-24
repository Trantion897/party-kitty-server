<?php
class Config {
    /**
     * Database settings (Only MySQL supported)
     */
    
    // Hostname of database server
    public const DB_HOST = "localhost";
    
    // Database username
    public const DB_USER = "";
    
    // Database password
    public const DB_PASSWORD = "";
    
    // Database name
    public const DB_DB = "";
    
    // Prefix for all table names
    public const DB_TABLE_PREFIX = "partykitty_";
    
    /**
     * Kitty name settings
     */
    // Path to a file defining words to use for kitty names
    public const WORD_LIST = "/usr/share/dict/words";
    
    // Minimum length of words in kitty names
    public const WORD_MIN_LENGTH = 4;
    
    // Maximum length of words in kitty names
    public const WORD_MAX_LENGTH = 8;
    
    /**
     * Expiration settings
     * 
     * Set when old party kitties will be deleted from the server.
     * ALL criteria must be met before a kitty is deleted.
     */
    
    // Expire kitties this long after the last update
    public const EXPIRATION_AFTER_LAST_UPDATE = '6 months';
    
    // Expire kitties this long after the last view/load
    public const EXPIRATION_AFTER_LAST_VIEW = '1 month';
    
    // Run expiration after a new kitty is created
    public const EXPIRE_AFTER_NEW = true;
    
    // Run expiration after an existing kitty is updated
    public const EXPIRE_AFTER_UPDATE = true;
    
    // Run expiration after kitty data is loaded
    public const EXPIRE_AFTER_GET = true;
    
    /**
     * Rate limiting settings
     * 
     * Applies rate limits to usage by IP address.
     * IP addresses are only stored for the rate limit period.
     * 
     * Set any limit field to 0 to disable
     */
    
    // Apply rate limits over this period (in seconds)
    public const RATE_LIMIT_PERIOD = 300;
    
    // Number of new kitties one user can create within the rate limit period
    public const RATE_LIMIT_CREATE_LIMIT = '1';
    
    // Number of different kitties one user can edit within the rate limit period
    public const RATE_LIMIT_UPDATE_LIMIT = '2';
    
    /**
     * Convenience methods, do not modify
     */
    public static function dbTableName($basename) {
        return self::DB_TABLE_PREFIX . $basename;
    }
}
