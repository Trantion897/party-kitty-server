<?php

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
    public static function random() {
        $dictionary = file("/usr/share/dict/words", FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        // TODO: Avoid words that are similar to others. Maybe use diceware word list.
        $dictionary = array_filter($dictionary, function($word) {
            return strlen($word) >= 4 && strlen($word) <= 8 && preg_match("/^[a-z]+$/", $word);
        });
        
        shuffle($dictionary);
        $existingStatement = $this->pdo->query("SELECT name from partykitty_data", PDO::FETCH_COLUMN, 0);
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
        $this->pdo = new PDO("mysql:host=localhost;dbname=party_kitty", "partykitty", "Dh1KKsO/1KXXqS17");
    }
    
    public function put() {
        // TODO: Updating an existing kitty will not be indempotent due to conflict resolution.
        // TODO: This part needs moving to a POST method.
        $putdata = json_decode(file_get_contents("php://input", "r"), true);
        
        if (array_key_exists("name", $putdata)) {
            // Validate existing name
            $name = KittyName::fromString($putdata['name'], true);
            $new = false;
            
            // Get balance before update
            $statement = $this->pdo->prepare("SELECT amount FROM partykitty_data WHERE name=:name LIMIT 1");
            $statement->execute(['name' => $name->format()]);
            $before = $statement->fetchColumn(0);
            
            // TODO: If $before === false, this name does not exist. Warn the user and/or create it. 
            // Maybe they were away for a long time and it was culled.
            
        } else {
            // Generate new name
            $name = KittyName::random();
            $new = true;
        }
        
        $statement = $this->pdo->prepare(
            "REPLACE INTO partykitty_data SET name=:name, currencySet=:currency, amount=:amount, partySize=:partySize, splitRatio=:splitRatio, config=:config, last_update=CURRENT_TIMESTAMP(), last_view=CURRENT_TIMESTAMP();"
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
        
        if ($new) {
            http_response_code(201);
        } else {
            http_response_code(200);
        }
        header("Content-Location: " . $name->format());
        
        $row = $this->loadData($name);
        
        print(json_encode([
            "name" => $name->format(),
            "amount" => json_decode($row['amount']),
            "lastUpdate" => $row['last_update'],
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
        print(json_encode([
            "name" => $name->format(),
            "amount" => json_decode($row['amount']),
            "partySize" => $row['partySize'],
            "splitRatio" => $row['splitRatio'],
            "config" => json_decode($row['config']),
            "lastUpdate" => $row['lastUpdate'],
            "lastView" => $row['lastView']
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
        $statement = $this->pdo->prepare("SELECT * FROM partykitty_data WHERE name=:name LIMIT 1");
        $statement->execute(['name' => $name->format()]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        
        return $row;
    }
    

}
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET,PUT");
$kitty = new KittyApi();

switch ($_SERVER['REQUEST_METHOD']) {
    case ("PUT"):
        $kitty->put();
        break;
    case ("GET"):
        $kitty->get();
        break;
}
