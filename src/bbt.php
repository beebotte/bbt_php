<?php
include_once "bbt_exceptions.php";

class Beebotte
{
    private $keyId = null;
    private $secretKey = null;
    private $port = null;
    private $hostname = null;
    
    private static $publicReadEndpoint = "/api/public/resource";
    private static $readEndpoint       = "/api/resource/read";
    private static $writeEndpoint      = "/api/resource/write";
    private static $bulkWriteEndpoint  = "/api/resource/bulk_write";
    private static $publishEndpoint    = "/api/event/write";

    public function __construct( $keyId, $secretKey, $hostname = "http://api.beebotte.com", $port = "80" )
    {
        $this->keyId     = $keyId;
        $this->secretKey = $secretKey;
        $this->hostname  = $hostname;
        $this->port      = $port;
    }

    private function processResponse ( $response )
    {
        $code   = $response['status'];
        $data   = $response['data'];
        if ($code < 400) {
            return $data;
        } else {
          $errcode = $data['error']['code'];
          $errmsg  = $data['error']['message'];
          if ($code == 400) {
            if ($errcode == 1101) throw AuthenticationError( "Status: 400; Code 1101; Message: " . $errmsg );
            elseif ($errcode == 1401) throw ParameterError("Status: 400; Code 1401; Message: " . $errmsg );
            elseif ($errcode == 1403) throw BadRequestError("Status: 400; Code 1403; Message: " . $errmsg );
            elseif ($errcode == 1404) throw TypeError("Status: 400; Code 1404; Message: " . $errmsg );
            elseif ($errcode == 1405) throw BadTypeError("Status: 400; Code 1405; Message: " . $errmsg );
            elseif ($errcode == 1406) throw PayloadLimitError("Status: 400; Code 1406; Message: " . $errmsg );
            else throw UnexpectedError("Status: " . $code . "; Code " . $errcode . "; Message: " . $errmsg );
          } elseif ($code == 405) {
            if ($errcode == 1102) throw NotAllowedError("Status: 405; Code 1102; Message: %s" % errmsg);
            else throw UnexpectedError("Status: " . $code . "; Code " . $errcode . "; Message: " . $errmsg );
          } elseif ($code == 500) {
            if ( $errcode == 1201 ) throw InternalError("Status: 500; Code 1201; Message: %s" % errmsg);
            else throw InternalError("Status: " . $code . "; Code " . $errcode . "; Message: " . $errmsg );
          } elseif ($code == 404) {
            if ($errcode == 1301) throw NotFoundError("Status: 404; Code 1301; Message: " . $errmsg);
            if ($errcode == 1302) throw NotFoundError("Status: 404; Code 1302; Message: " . $errmsg);
            if ($errcode == 1303) throw NotFoundError("Status: 404; Code 1303; Message: " . $errmsg);
            if ($errcode == 1304) throw AlreadyExistError("Status: 404; Code 1304; Message: " . $errmsg);
            if ($errcode == 1305) throw AlreadyExistError("Status: 404; Code 1305; Message: " . $errmsg);
            if ($errcode == 1306) throw AlreadyExistError("Status: 404; Code 1306; Message: " . $errmsg);
            else throw UnexpectedError("Status: " . $code . "; Code " . $errcode . "; Message: " . $errmsg );
          } else {
            throw UnexpectedError("Status: " . $code . "; Code " . $errcode . "; Message: " . $errmsg );
          }
        }
    }
    
    private function signRequest($verb, $uri, $date, $c_type, $c_md5 = null)
    {
        $stringToSign = $verb . "\n" . $c_md5 . "\n" . $c_type . "\n" . $date . "\n" . $uri;
        return ($this->keyId . ":" . base64_encode(hash_hmac("sha1", $stringToSign, $this->secretKey, true)));
    }

    private function exec_curl( $ch ) {
        $response = array();

        $response[ "data" ] = json_decode(curl_exec( $ch ), true);
        $response[ "status" ] = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close( $ch );

        return $this->processResponse( $response );
    }

    private function postData($uri, $body, $auth = true)
    {
        # Set cURL options
        $ch = curl_init();
        
        if ( $ch === false )
        {
            throw new BeebotteException("Error while initializing cURL!");
        }
        
        $url  = $this->hostname . ":" . $this->port . $uri;
        $md5  = base64_encode(md5($body, true));
        
        $date = date(DATE_RFC2822);
        
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_POST, true );
        
        if( $auth === true ) {
            $sig = $this->signRequest("POST", $uri, $date, "application/json", $md5);
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array ( "Content-MD5: " . $md5, "Content-Type: application/json", "Date: " . $date , "Authorization: " . $sig ) );
        }else {
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array ( "Content-MD5: " . $md5, "Content-Type: application/json", "Date: " . $date ) );
        }
        
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
        
        return $this->exec_curl( $ch );
    }

    private function getData($uri, $query, $auth = true)
    {
        # Set cURL options
        $ch = curl_init();
        
        if ( $ch === false )
        {
            throw new BeebotteException("Error while initializing cURL!");
        }
        
        $full_uri  = $uri . "?" . http_build_query( $query );
        $url  = $this->hostname . ":" . $this->port . $full_uri;
        $date = date(DATE_RFC2822);

        curl_setopt( $ch, CURLOPT_URL, $url );
        
        if( $auth === true ) {
            $sig = $this->signRequest("GET", $full_uri, $date, "application/json");
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array ( "Content-Type: application/json", "Date: " . $date , "Authorization: " . $sig ) );
        }else {
            curl_setopt( $ch, CURLOPT_HTTPHEADER, array ( "Content-Type: application/json", "Date: " . $date ) );
        }
        
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        
        return $this->exec_curl( $ch );
    }

    public function publicRead( $owner, $device, $service, $resource, $limit = 1, $source = "live", $metric = "avg" )
    {
        $query = array();
        $query["owner"]    = $owner;
        $query["device"]   = $device;
        $query["service"]  = $service;
        $query["resource"] = $resource;
        $query["limit"]    = $limit;
        $query["source"]   = $source;
        $query["metric"]   = $metric;
        
        $response = $this->getData( self::$publicReadEndpoint, $query, false );

        return $response;
    }  

    public function read( $device, $service, $resource, $limit = 1, $source = "live", $metric = "avg" )
    {
        $query = array();
        $query["device"]   = $device;
        $query["service"]  = $service;
        $query["resource"] = $resource;
        $query["limit"]    = $limit;
        $query["source"]   = $source;
        $query["metric"]   = $metric;
        
        $response = $this->getData( self::$readEndpoint, $query, true );

        return $response;
    }  

    public function write( $device, $service, $resource, $value, $ts = null, $type = "attribute" )
    {
        $data = array();
        $data["device"]   = $device;
        $data["service"]  = $service;
        $data["resource"] = $resource;
        $data["value"]    = $value;

        $response = $this->postData( self::$writeEndpoint, json_encode( $data ), true );

        return $response;
    }  

    public function bulkWrite( $device, $data_array )
    {
        $data = array();
        $data["device"]   = $device;
        $data["data"]  = $data_array;
        
        $response = $this->postData( self::$bulkWriteEndpoint, json_encode( $data ), true );

        return $response;
    }  

    public function publish( $device, $service, $resource, $value, $ts = null, $source = null )
    {
        $data = array();
        $data["device"]   = $device;
        $data["service"]  = $service;
        $data["resource"] = $resource;
        $data["data"]     = $value;
        
        if( $source != null ) {
          $data["source"] = $source;
        }
        
        $response = $this->postData( self::$publishEndpoint, json_encode( $data ), true );

        return $response;
    }  

    public function auth_client($sid, $device, $service = '*', $resource = '*', $ttl = 0, $read = false, $write = false)
    {
        $r = $read ? "true" : "false";
        $w = $write? "true" : "false";
        $stringToSign = $sid . ":" . $device . "." . $service . "." . $resource . ":ttl=" . $ttl . ":read=" . $r . ":write=" . $w;
        return ($this->keyId . ":" . base64_encode(hash_hmac("sha1", $stringToSign, $this->secretKey, true)));
    }
}

class Resource {
    private $device   = null;
    private $serv  = null;
    private $res = null;
    private $bbt      = null;

    public function __construct( $bbt, $device, $service, $resource )
    {
        $this->bbt    = $bbt;
        $this->device = $device;
        $this->serv   = $service;
        $this->res    = $resource;
    }

    public function write($value, $ts = null) {
        return $this->bbt->write($this->device, $this->serv, $this->res, $value, $ts);
    }

    public function publish($value, $ts = null) {
        return $this->bbt->publish($this->device, $this->serv, $this->res, $value, $ts);
    }

    public function read($owner = null, $limit = 1, $source = "live", $metric = "avg") {
        if($owner != null) {
            return $this->bbt->publicRead($owner, $this->device, $this->serv, $this->res, $limit, $source, $metric);
        }else {
            return $this->bbt->read($this->device, $this->serv, $this->res, $limit, $source, $metric);
        }
    }

    public function recentValue() {
        return $this->bbt->read($this->device, $this->serv, $this->res)[0];
    }
}
?>
