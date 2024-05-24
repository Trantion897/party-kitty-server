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
        
    }
    
    public function post() {
        $postData = json_decode(file_get_contents("php://input", "r"), true);
        // Validate existing name
        $name = KittyName::fromString($postData['name'], true);
        
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
