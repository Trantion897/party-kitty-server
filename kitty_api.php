<?php

class KittyApi {
    
    private PDO $pdo;
    
    public function __construct() {
        $this->pdo = new PDO("mysql:host=localhost;dbname=party_kitty", "partykitty", "Dh1KKsO/1KXXqS17");
    }
    
    public function put() {
        $putdata = json_decode(file_get_contents("php://input", "r"), true);
        
        if (array_key_exists("name", $putdata)) {
            // Validate existing name
            $tempName = strtolower($putdata['name']);
            $name = explode("-", $tempName);
            if (count($name) != 2 || !preg_match("/^[A-z]+-[A-z]+$/", $tempName)) {
                throw new Exception ("Invalid name");
            }
            $new = false;
            
            // Get balance before update
            $statement = $this->pdo->prepare("SELECT amount FROM partykitty_data WHERE name=:name LIMIT 1");
            $statement->execute(['name' => $this->format_name($name)]);
            $before = $statement->fetchColumn(0);
            
            // TODO: If $before === false, this name does not exist. Warn the user and/or create it. 
            // Maybe they were away for a long time and it was culled.
            
        } else {
            // Generate new name
            $name = $this->make_name();
            $new = true;
        }
        
        $statement = $this->pdo->prepare(
            "REPLACE INTO partykitty_data SET name=:name, currencySet=:currency, amount=:amount, partySize=:partySize, splitRatio=:splitRatio, config=:config, last_update=CURRENT_TIMESTAMP(), last_view=CURRENT_TIMESTAMP();"
        );
        
        $amount = $putdata['amount'];
        
        // TODO: Clean up the JSON values
        $statement->execute([
            'name'      => $this->format_name($name),
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
        header("Content-Location: " . $this->format_name($name));
        
        $statement = $this->pdo->prepare("SELECT * FROM partykitty_data WHERE name=:name LIMIT 1");
        $statement->execute(['name' => $this->format_name($name)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        
        print(json_encode([
            "name" => $this->format_name($name),
            "amount" => $row['amount'],
            "last_update" => $row['last_update'],
        ]));
        
    }
    
    private function format_name(array $name) {
        return $name[0] . "-" . $name[1];
    }

    /**
    * Create a random name consisting of two words from the system dictionary,
    * that is not already used by any existing entry in the database
    */
    private function make_name() {
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
            
            $words = [array_shift($dictionary), array_shift($dictionary)];
        } while (in_array($this->format_name($words), $existingNames));
        
        return $words;
    }
}
header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: GET,PUT");
$kitty = new KittyApi();

switch ($_SERVER['REQUEST_METHOD']) {
    case ("PUT"):
        $kitty->put();
}
