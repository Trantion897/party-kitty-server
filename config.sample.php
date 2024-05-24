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
     * Convenience methods, do not modify
     */
    public static function dbTableName($basename) {
        return self::DB_TABLE_PREFIX . $basename;
    }
}
