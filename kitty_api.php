<?php
define("HEADER_IF_MODIFIED_SINCE", "If-Modified-Since");
define("PHP_HEADER_IF_MODIFIED_SINCE", "HTTP_IF_MODIFIED_SINCE");
define("DATE_FORMAT_MYSQL", "Y-m-d H:i:s");

require_once("config.php");

class KittyName {
    private array $name;
    
    private function __construct(array $name) {
        $this->name = $name;
    }
    
    /**
     * Creates a new name object from a potentially dirty string from the client
     * 
     * @param string $input              Name as received
     * @param bool   $exceptionOnFailure Throw an exception if the name is invalid
     * 
     * @return KittyName|bool Cleaned name, or false if not valid
     * 
     * @throws Exception Name is invalid (optional)
     */
    public static function fromString(string $input, bool $exceptionOnFailure=false) {
        $tempName = strtolower($input);
        $arrName = explode("-", $tempName);
        if (count($arrName) != 2 || !preg_match("/^[A-z]+-[A-z]+$/", $tempName)) {
            if ($exceptionOnFailure) {
                throw new Exception("Invalid name");
            } else {
                return false;
            }
        }
        
        return new KittyName($arrName);
    }
    
    /**
     * Create a random name consisting of two words from the system dictionary,
     * that is not already used by any existing entry in the database
     */
    public static function random($pdo) {
        $dictionary = file(Config::WORD_LIST, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        // TODO: Avoid words that are similar to others. Maybe use diceware word list.
        $dictionary = array_filter($dictionary, function($word) {
            return strlen($word) >= Config::WORD_MIN_LENGTH && 
                   strlen($word) <= Config::WORD_MAX_LENGTH && 
                   preg_match("/^[a-z]+$/", $word);
        });
        
        shuffle($dictionary);
        $existingStatement = $pdo->query("SELECT name from ".Config::DB_TABLE_PREFIX."data", PDO::FETCH_COLUMN, 0);
        $existingNames = $existingStatement->fetchAll(PDO::FETCH_COLUMN, 0);
                
        do {
            if (count($dictionary) < 2) {
                throw new Exception("Dictionary exhausted, no new words available");
            }
            
            $name = new KittyName([array_shift($dictionary), array_shift($dictionary)]);
        } while (in_array($name->format(), $existingNames));
        
        return $name;
    }
    
    public function format() {
        return $this->name[0] . "-" . $this->name[1];
    }
}

class KittyApi {
    
    private PDO $pdo;
    
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    
    public function __construct() {
        $connect = sprintf('mysql:host=%1$s;dbname=%2$s', Config::DB_HOST, Config::DB_DB);
        $this->pdo = new PDO($connect, Config::DB_USER, Config::DB_PASSWORD);
    }
    
    public function put() {
        // TODO: Updating an existing kitty will not be indempotent due to conflict resolution.
        // TODO: This part needs moving to a POST method.
        $putdata = json_decode(file_get_contents("php://input", "r"), true);
        
        // Generate new name
        $name = KittyName::random($this->pdo);
        
        $this->applyRateLimits($_SERVER['REMOTE_ADDR'], self::ACTION_CREATE, $name);
        
        // TODO: Capture PK violations
        $statement = $this->pdo->prepare(
            "INSERT INTO ".Config::dbTableName("data")." SET name=:name, currencySet=:currency, amount=:amount, partySize=:partySize, splitRatio=:splitRatio, config=:config, last_update=UTC_TIMESTAMP(), last_view=UTC_TIMESTAMP();"
        );
        
        $amount = $putdata['amount'];
        
        // TODO: Clean up the JSON values
        $statement->execute([
            'name'      => $name->format(),
            'currency'  => $putdata['currency'],
            'amount'    => json_encode($amount),
            'partySize' => (int)$putdata['partySize'],
            'splitRatio'=> (int)$putdata['splitRatio'],
            'config'    => json_encode($putdata['config']),
        ]);
        
        http_response_code(201);
        header("Content-Location: " . $name->format());
        
        $row = $this->loadData($name);
        
        print(json_encode([
            "name" => $name->format(),
            "amount" => json_decode($row['amount']),
            "lastUpdate" => $row['last_update']->format(DateTimeInterface::ISO8601),
        ]));
        
        if (Config::EXPIRE_AFTER_NEW) {
            $this->expire();
        }
        
    }
    
    public function post() {
        $postData = json_decode(file_get_contents("php://input", "r"), true);
        // Validate existing name
        $name = KittyName::fromString($postData['name'], true);
        
        $this->applyRateLimits($_SERVER['REMOTE_ADDR'], self::ACTION_UPDATE, $name);
                
        // Get balance before update
        $statement = $this->pdo->prepare("SELECT last_update, amount FROM ".Config::dbTableName("data")." WHERE name=:name LIMIT 1");
        $statement->execute(['name' => $name->format()]);
        $beforeAmount = json_decode($statement->fetchColumn(1));
        
        // Check the client's last update is not later than the server's update
        $clientLastUpdate = DateTimeImmutable::createFromFormat(DateTimeInterface::ISO8601, $postData['lastUpdate']);
        $serverLastUpdate = DateTimeImmutable::createFromFormat(DATE_FORMAT_MYSQL, $statement->fetchColumn(0));
        
        if ($clientLastUpdate > $serverLastUpdate) {
            http_response_code(400);
            print(json_encode([
                "error" => "INVALID_LAST_UPDATE"
            ]));
            return;
        }
        
        // Calculate the diff the client is sending
        $newValue = [];
        $error = false;
        foreach($beforeAmount as $currency => $serverValue) {
            if (!array_key_exists($currency, $postData['lastUpdateAmount'])) {
                http_response_code(400);
                print(json_encode([
                    "error" => "CURRENCY_MISSING_LAST_UPDATE",
                    "errorParam" => $currency,
                ]));
            }
            if (!array_key_exists($currency, $postData['amount'])) {
                http_response_code(400);
                print(json_encode([
                    "error" => "CURRENCY_MISSING_AMOUNT",
                    "errorParam" => $currency,
                ]));
            }
            
            // TODO: Maybe allow floats, but use ints if possible
            $beforeValue = (int)$postData['lastUpdateAmount'][$currency];
            $afterValue = (int)$postData['amount'][$currency];
            
            if ($beforeValue == $serverValue) {
                $newValue[$currency] = $afterValue;
            } else {
                $currencyDiff = $afterValue - $beforeValue;
                $newValue[$currency] = $serverValue + $currencyDiff;
            }
        }
        if ($error) {
            // TODO: try/catch
            return;
        }
        
        $statement = $this->pdo->prepare(
            "UPDATE ".Config::dbTableName("data")." SET currencySet=:currency, amount=:amount, partySize=:partySize, splitRatio=:splitRatio, config=:config, last_update=UTC_TIMESTAMP(), last_view=UTC_TIMESTAMP() WHERE name=:name;"
        );
        
        // TODO: Clean up the JSON values
        $statement->execute([
            'name'      => $name->format(),
            'currency'  => $postData['currency'],
            'amount'    => json_encode($newValue),
            'partySize' => (int)$postData['partySize'],
            'splitRatio'=> (int)$postData['splitRatio'],
            'config'    => json_encode($postData['config']),
        ]);
        
        $row = $this->loadData($name);
                
        print(json_encode([
            "name" => $name->format(),
            "amount" => json_decode($row['amount']),
            "lastUpdate" => $row['last_update']->format(DateTimeInterface::ISO8601),
        ]));
        
        if (Config::EXPIRE_AFTER_UPDATE) {
            $this->expire();
        }
    }
    
    public function get() {
        $name = KittyName::fromString($_GET['name']);
        
        if ($name === false) {
            // TODO: Merge these 404 checks - maybe add a check to KittyName?
            http_response_code(404);
            return;
        }
        
        $row = $this->loadData($name);
        
        if ($row === false) {
            http_response_code(404);
            return;
        }
        
        // Check if kitty has been modified
        if (array_key_exists(PHP_HEADER_IF_MODIFIED_SINCE, $_SERVER)) {
            $clientLastUpdate = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $_SERVER[PHP_HEADER_IF_MODIFIED_SINCE], new DateTimeZone("UTC"));
            $serverLastUpdate = $row['last_update'];
            
            if ($clientLastUpdate > $serverLastUpdate) {
                http_response_code(400);
                return;
            } else if ($clientLastUpdate == $serverLastUpdate) {
                http_response_code(304);
                return;
            }
        }
        
        print(json_encode([
            "name" => $name->format(),
            "amount" => json_decode($row['amount']),
            "partySize" => $row['partySize'],
            "splitRatio" => $row['splitRatio'],
            "config" => json_decode($row['config']),
            "lastUpdate" => $row['last_update']->format(DateTimeInterface::ISO8601),
            "lastView" => $row['last_view']->format(DateTimeInterface::ISO8601)
        ]));
        
        if (Config::EXPIRE_AFTER_GET) {
            $this->expire();
        }
    }
    
    /**
     * Load data for an existing party kitty
     * 
     * @param KittyName $name The name of the kitty
     * 
     * @return array
     */
    public function loadData(KittyName $name) {
        $statement = $this->pdo->prepare("SELECT * FROM ".Config::dbTableName("data")." WHERE name=:name LIMIT 1");
        $statement->execute(['name' => $name->format()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $row['last_update'] = DateTimeImmutable::createFromFormat(DATE_FORMAT_MYSQL, $row['last_update'], new DateTimeZone("UTC"));
            $row['last_view'] = DateTimeImmutable::createFromFormat(DATE_FORMAT_MYSQL, $row['last_view'], new DateTimeZone("UTC"));
        }
        
        return $row;
    }
    
    /**
     * Expire old kitties according to settings
     */
    public function expire() {
        $lastUpdateInterval = DateInterval::createFromDateString(Config::EXPIRATION_AFTER_LAST_UPDATE);
        $lastViewInterval = DateInterval::createFromDateString(Config::EXPIRATION_AFTER_LAST_VIEW);
        $now = new DateTimeImmutable("now", new DateTimeZone("UTC"));

        $statement = $this->pdo->prepare(
            'DELETE FROM '.Config::dbTableName('data').
            ' WHERE last_update < :last_update AND last_view < :last_view'
        );
        
        $lastUpdateDate = $now->sub($lastUpdateInterval)->format(DATE_FORMAT_MYSQL);
        $lastViewDate = $now->sub($lastViewInterval)->format(DATE_FORMAT_MYSQL);
        
        $statement->execute([
            'last_update' => $lastUpdateDate,
            'last_view' => $lastViewDate
        ]);
        
        if ($statement->rowCount() > 0) {
            trigger_error(sprintf('%1$d kitty/ies were expired due to lack of activity', $statement->rowCount()));
        }
    }
    
    /**
     * Check rate limits applying to the current request and terminate with appropriate error
     */
    public function applyRateLimits($ip, $action, KittyName $kitty) {
        if (!$this->checkRateLimits($ip, $action, $kitty)) {
            // TODO: Could calculate the time until the oldest record expires
            http_response_code(429);
            header('Retry-After: '.Config::RATE_LIMIT_PERIOD);
            // Because browsers don't expose the Retry-After header
            print(json_encode(['RetryAfter' => Config::RATE_LIMIT_PERIOD]));
            exit();
        }
    }
    
    /**
     * Expire any rate limit data from before the rate limit period
     */
    public function expireRateLimits() {
        $now = new DateTimeImmutable("now", new DateTimeZone("UTC"));
        $expiration = $now->sub(new DateInterval('PT'.Config::RATE_LIMIT_PERIOD.'S'));
        
        $statement = $this->pdo->prepare(
            'DELETE FROM '.Config::dbTableName('ratelimit').
            ' WHERE timestamp < :timestamp'
        );
        
        $statement->execute(['timestamp' => $expiration->format(DATE_FORMAT_MYSQL)]);
    }
    
    /**
     * Checks the rate limits that apply to an action
     * 
     * Returns true or false to indicate if this action is allowed.
     * If the result is true, the action is stored in the rate limit table.
     * Outdated rate limit data is deleted
     */
    public function checkRateLimits($ip, $action, KittyName $kitty=null) {
        $this->expireRateLimits();
        
        switch ($action) {
            case self::ACTION_CREATE:
                if (empty(Config::RATE_LIMIT_CREATE_LIMIT)) {
                    return true;
                }
                $appliedLimit = Config::RATE_LIMIT_CREATE_LIMIT;
                break;
            case self::ACTION_UPDATE:
                if (empty(Config::RATE_LIMIT_UPDATE_LIMIT)) {
                    return true;
                }
                $appliedLimit = Config::RATE_LIMIT_UPDATE_LIMIT;
                break;
            default:
                throw new Exception("Unknown action for rate limit: $action");
        }
        
        // Get recent actions
        $sql = 'SELECT COUNT(1) FROM '.Config::dbTableName('ratelimit').
               ' WHERE IP = :ip AND action = :action';
        $params = [
            'ip' => $ip,
            'action' => $action,
        ];
        if (isset($kitty)) {
            $sql .= ' AND kitty != :kitty';
            $params['kitty'] = $kitty->format();
        }
        $statement = $this->pdo->prepare($sql);
        
        $statement->execute($params);
        
        if ($statement->fetchColumn(0) >= $appliedLimit) {
            return false;
        }
        
        // Rate limit NOT applied, add a new record for this action
        $statement = $this->pdo->prepare(
            'REPLACE INTO '.Config::dbTableName('ratelimit').
            ' SET ip=:ip, action=:action, kitty=:kitty, timestamp=UTC_TIMESTAMP()'
        );
        
        $statement->execute([
            'ip' => $ip,
            'action' => $action,
            'kitty' => $kitty->format()
        ]);
        
        return true;
    }
}
header("Access-Control-Allow-Origin: *"); // TODO
header("Access-Control-Allow-Methods: GET,PUT,POST,OPTIONS");
header("Access-Control-Allow-Headers: " . HEADER_IF_MODIFIED_SINCE);
$kitty = new KittyApi();

// TODO: Common sanitisation method
switch ($_SERVER['REQUEST_METHOD']) {
    case ("PUT"):
        $kitty->put();
        break;
    case ("GET"):
        $kitty->get();
        break;
    case ("POST"):
        $kitty->post();
        break;
    case("OPTIONS"):
        print("");
        break;
}
