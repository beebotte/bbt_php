<?php
 
include_once "bbt_exceptions.php";

/**
 * @package Beebotte
 * PHP library for interfacing with Beebotte.
 * Beebotte is a Cloud platform for the Internet of Things and real time connected applications
 * Contains methods for sending persistent and transient messages and for reading data.
 */
class Beebotte
{
    private $keyId = null;
    private $secretKey = null;
    private $port = null;
    private $hostname = null;
    
    private static $publicReadEndpoint  = "/v1/public/data/read";
    private static $readEndpoint        = "/v1/data/read";
    private static $writeEndpoint       = "/v1/data/write";
    private static $bulkWriteEndpoint   = "/v1/data/write";
    private static $publishEndpoint     = "/v1/data/publish";
    private static $bulkPublishEndpoint = "/v1/data/publish";

    /**
     * Beebotte
     *
     * Constructor, initializes the Beebotte client connector API
     *
     * @param string $keyId required user's API key (access key) to be passed along the authentication parameters.
     * @param string $secretKey required user's secret key to be used to sign API calls.
     * @param string $hostname the host name where the API is implemented.
     * @param short  $port The port number.
     */
    public function __construct( $keyId, $secretKey, $hostname = "http://api.beebotte.com", $port = "80" )
    {
        $this->keyId     = $keyId;
        $this->secretKey = $secretKey;
        $this->hostname  = $hostname;
        $this->port      = $port;
    }

    /**
     * Checks if the given response data is OK or if an error occurred
     *
     * @param array $response required response containing the status code and data
     * @return array response data in JSON if the status is OK, raises an exception otherwise (with the status code, error code and error message)
     */
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
            if ($errcode == 1101) throw new AuthenticationError( "Status: 400; Code 1101; Message: " . $errmsg );
            elseif ($errcode == 1401) throw new ParameterError("Status: 400; Code 1401; Message: " . $errmsg );
            elseif ($errcode == 1403) throw new BadRequestError("Status: 400; Code 1403; Message: " . $errmsg );
            elseif ($errcode == 1404) throw new TypeError("Status: 400; Code 1404; Message: " . $errmsg );
            elseif ($errcode == 1405) throw new BadTypeError("Status: 400; Code 1405; Message: " . $errmsg );
            elseif ($errcode == 1406) throw new PayloadLimitError("Status: 400; Code 1406; Message: " . $errmsg );
            else throw new UnexpectedError("Status: " . $code . "; Code " . $errcode . "; Message: " . $errmsg );
          } elseif ($code == 405) {
            if ($errcode == 1102) throw new NotAllowedError("Status: 405; Code 1102; Message: %s" % errmsg);
            else throw new UnexpectedError("Status: " . $code . "; Code " . $errcode . "; Message: " . $errmsg );
          } elseif ($code == 500) {
            if ( $errcode == 1201 ) throw new InternalError("Status: 500; Code 1201; Message: %s" % errmsg);
            else throw new InternalError("Status: " . $code . "; Code " . $errcode . "; Message: " . $errmsg );
          } elseif ($code == 404) {
            if ($errcode == 1301) throw new NotFoundError("Status: 404; Code 1301; Message: " . $errmsg);
            if ($errcode == 1302) throw new NotFoundError("Status: 404; Code 1302; Message: " . $errmsg);
            if ($errcode == 1303) throw new NotFoundError("Status: 404; Code 1303; Message: " . $errmsg);
            if ($errcode == 1304) throw new AlreadyExistError("Status: 404; Code 1304; Message: " . $errmsg);
            if ($errcode == 1305) throw new AlreadyExistError("Status: 404; Code 1305; Message: " . $errmsg);
            if ($errcode == 1306) throw new AlreadyExistError("Status: 404; Code 1306; Message: " . $errmsg);
            else throw new UnexpectedError("Status: " . $code . "; Code " . $errcode . "; Message: " . $errmsg );
          } else {
            throw new UnexpectedError("Status: " . $code . "; Code " . $errcode . "; Message: " . $errmsg );
          }
        }
    }
    
    /**
     * Creates a signature of an API call to authenticate the user and verify message integrity.
     *
     * @param string $verb required The HTTP verb (method) in upper case.
     * @param string $uri required the API endpoint containing the query parameters.
     * @param string $date required The date on the caller side.
     * @param string $c_type required The Content type header, should be application/json.
     * @param string $c_md5 optional The content MD5 hash of the data to send (should be set for POST requests)
     *
     * @return string the signature (keyid:hash) of the API call to be added as authorization header in the request to send.
     */
    private function signRequest($verb, $uri, $date, $c_type, $c_md5 = null)
    {
        $stringToSign = $verb . "\n" . $c_md5 . "\n" . $c_type . "\n" . $date . "\n" . $uri;
        return ($this->keyId . ":" . base64_encode(hash_hmac("sha1", $stringToSign, $this->secretKey, true)));
    }

    /**
     * Executes the HTTP call requests and returns a JSON object with status code and response data.
     *
     * @return array JSON object with status code and response data.
     */
    private function exec_curl( $ch ) {
        $response = array();

        $response[ "data" ] = json_decode(curl_exec( $ch ), true);
        $response[ "status" ] = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close( $ch );

        return $this->processResponse( $response );
    }

    /**
     * Sends a POST request with the given data to the given URI endpoint and returns the response data.
     *
     * @param string $uri required the uri endpoint.
     * @param string $body required the data to send.
     * @param boolean $auth optional Indicates if the Post request should be authenticated (defaults to true).
     * 
     * @return array The response data in JSON format if success, raises an error or failure.
     */
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

    /**
     * Sends a GET request with the given query parameters to the given URI endpoint and returns the response data.
     *
     * @param string $uri required the uri endpoint.
     * @param array $query required the query parameters in JSON format.
     * @param boolean $auth optional Indicates if the Post request should be authenticated (defaults to true).
     * 
     * @return array The response data in JSON format if success, raises an error or failure.
     */
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
    
    private function getPublicReadUrl($owner, $channel, $resource) {
        return self::$publicReadEndpoint . "/" . $owner . "/" . $channel . "/" . $resource;
    }

    private function getReadUrl($channel, $resource) {
        return self::$readEndpoint . "/" . $channel . "/" . $resource;
    }

    private function getWriteUrl($channel, $resource) {
        return self::$writeEndpoint . "/" . $channel . "/" . $resource;
    }

    private function getBulkWriteUrl($channel) {
        return self::$bulkWriteEndpoint . "/" . $channel;
    }

    private function getPublishUrl($channel, $resource) {
        return self::$publishEndpoint . "/" . $channel . "/" . $resource;
    }

    private function getBulkPublishUrl($channel) {
        return self::$bulkPublishEndpoint . "/" . $channel;
    }

    /**
     * Public Read
     * Reads data from the resource with the given metadata. This method expects the resource to have public access. 
     * In Beebotte, resources follow a 2 level hierarchy: channel -> Resource
     * Data is always associated with Resources.
     * This call will not be signed (no authentication required) as the resource is public.
     *
     * @param string $owner required the owner (username) of the resource to read from.
     * @param string $channel required the channel name.
     * @param string $resource required the resource name to read from.
     * @param integer $limit optional number of records to return.
     * @param string $source optional indicates whether to read from live data or from historical statistics. Accepts ('live', 'hour-stats', 'day-stats').
     * @param string $timerange optional indicates the time range for the records to read. 
     *        Accepts ('Xhour', 'Xday, 'Xweek', 'Xmonth') with X a positive integer or 
     *        one of ('today', 'yesterday', 'current-week', 'last-week', 'current-month', 'last-month', 'ytd')
     * 
     * @return array of records (JSON) on success, raises an error or failure.
     */
    public function publicRead( $owner, $channel, $resource, $limit = null, $source = null, $timerange = null )
    {
        $query = array();
        if( $limit ) $query["limit"]    = $limit;
        if( $source ) $query["source"]   = $source;
        if( $timerange ) $query["time-range"]   = $timerange;

        $response = $this->getData( $this->getPublicReadUrl( $owner, $channel, $resource ), $query, false );

        return $response;
    }  

    /**
     * Read
     * Reads data from the resource with the given metadata.  
     * In Beebotte, resources follow a 2 level hierarchy: channel -> Resource
     * Data is always associated with Resources.
     * This call will be signed to authenticate the calling user.
     *
     * @param string $channel required the channel name.
     * @param string $resource required the resource name to read from.
     * @param integer $limit optional number of records to return.
     * @param string $source optional indicates whether to read from live data or from historical statistics. Accepts ('live', 'hour-stats', 'day-stats').
     * @param string $timerange optional indicates the time range for the records to read. 
     *        Accepts ('Xhour', 'Xday, 'Xweek', 'Xmonth') with X a positive integer or 
     *        one of ('today', 'yesterday', 'current-week', 'last-week', 'current-month', 'last-month', 'ytd')
     * 
     * @return array of records (JSON) on success, raises an error or failure.
     */
    public function read( $channel, $resource, $limit = null, $source = null, $timerange = null )
    {
        $query = array();
        if( $limit ) $query["limit"]    = $limit;
        if( $source ) $query["source"]   = $source;
        if( $timerange ) $query["time-range"]   = $timerange;
        
        $response = $this->getData( $this->getReadUrl( $channel, $resource ), $query, true );

        return $response;
    }  

    /**
     * Write (Persistent messages)
     * Writes data to the resource with the given metadata. 
     * In Beebotte, resources follow a 2 level hierarchy: channel -> Resource
     * Data is always associated with Resources.
     * This call will be signed to authenticate the calling user.
     *
     * @param string $channel required the channel name.
     * @param string $resource required the resource name to write to.
     * @param mixed $data required the data value to write (persist).
     * @param integer $ts optional timestamp in milliseconds (since epoch). If this parameter is not given, it will be automatically added with a value equal to the local system time.
     * 
     * @return boolean true on success, raises an error or failure.
     */
    public function write( $channel, $resource, $data, $ts = null )
    {
        $body = array();
        $body["data"] = $data;

        $response = $this->postData( $this->getWriteUrl( $channel, $resource ), json_encode( $body ), true );

        return $response;
    }  

    /**
     * Bulk Write (Persistent messages)
     * Writes an array of data in one API call. 
     * In Beebotte, resources follow a 2 level hierarchy: channel -> Resource
     * Data is always associated with Resources.
     * This call will be signed to authenticate the calling user.
     *
     * @param string $channel required the channel name.
     * @param array $data_array required the data array to send. Should follow the following format
     * [{
     *   string resource required the resource name to write to.
     *   mixed data required the data value to write (persist).
     *   integer ts optional timestamp in milliseconds (since epoch). If this parameter is not given, it will be automatically added with a value equal to the local system time.
     * }]
     * 
     * @return boolean true on success, raises an error or failure.
     */
    public function writeBulk( $channel, $data_array )
    {
        $body = array();
        $body["records"] = $data_array;
        
        $response = $this->postData( $this->getBulkWriteUrl( $channel ), json_encode( $body ), true );

        return $response;
    }  

    /**
     * Publish (Transient messages)
     * Publishes data to the resource with the given metadata. The published data will not be persisted. It will only be delivered to connected subscribers.
     * In Beebotte, resources follow a 2 level hierarchy: channel -> Resource
     * Data is always associated with Resources.
     * This call will be signed to authenticate the calling user.
     *
     * @param string $channel required the channel name.
     * @param string $resource required the resource name to publish to.
     * @param mixed $data required the data value to publish (transient).
     * @param integer $ts optional timestamp in milliseconds (since epoch). If this parameter is not given, it will be automatically added with a value equal to the local system time.
     * 
     * @return boolean true on success, raises an error or failure.
     */
    public function publish( $channel, $resource, $data, $ts = null )
    {
        $body = array();
        $body["data"] = $data;
        
        $response = $this->postData( $this->getPublishUrl( $channel, $resource ), json_encode( $body ), true );

        return $response;
    }  

    /**
     * Bulk Publish (Transient messages)
     * Published an array of data in one API call. 
     * In Beebotte, resources follow a 2 level hierarchy: channel -> Resource
     * Data is always associated with Resources.
     * This call will be signed to authenticate the calling user.
     *
     * @param string $channel required the channel name.
     * @param array $data_array required the data array to send. Should follow the following format
     * [{
     *   string resource required the resource name to publish to.
     *   mixed data required the value to publish
     *   integer ts optional timestamp in milliseconds (since epoch). If this parameter is not given, it will be automatically added with a value equal to the local system time.
     *   string $type optional default to 'attribute'. This is for future use.
     * }]
     * 
     * @return boolean true on success, raises an error or failure.
     */
    public function publishBulk( $channel, $data_array )
    {
        $body = array();
        $body["records"]  = $data_array;
        
        $response = $this->postData( $this->getBulkWriteUrl( $channel ), json_encode( $body ), true );

        return $response;
    }  

    /**
     * Client Authentication (used for the Presence and Resource subscription process)
     * Signs the given subscribe metadata and returns the signature.
     *
     * @param string $sid required the session id of the client.
     * @param string $channel required the channel name. Should start with 'presence:' for presence channels and starts with 'private:' for private channels.
     * @param string $resource optional the resource name to read from.
     * @param integer $ttl optional the number of seconds the signature should be considered as valid (currently ignored) for future use.
     * @param boolean $read optional indicates if read access is requested.
     * @param boolean $write optional indicates if write access is requested.
     * 
     * @return array containing 'auth' element with value equal to the generated signature.
     */
    public function auth_client($sid, $channel, $resource = '*', $ttl = 0, $read = false, $write = false)
    {
        $r = $read ? "true" : "false";
        $w = $write? "true" : "false";
        $stringToSign = $sid . ":" . $channel . "." . $resource . ":ttl=" . $ttl . ":read=" . $r . ":write=" . $w;
        $auth = array();
        $auth["auth"] = $this->keyId . ":" . base64_encode(hash_hmac("sha1", $stringToSign, $this->secretKey, true));
        return json_encode( $auth );
    }
}

/**
 * @class
 * Utility class for dealing with Resources
 * Contains methods for sending persistent and transient messages and for reading data.
 * Mainly wrappers around Beebotte API calls. 
 */
class Resource {
    private $channel  = null;
    private $res      = null;
    private $bbt      = null;

    /**
     * Resource
     *
     * Constructor, initializes the Resource object.
     * In Beebotte, resources follow a 3level hierarchy: channel -> Resource
     * Data is always associated with Resources.
     *
     * @param object $bbt required reference to the Beebotte client connector.
     * @param string $channel required channel name.
     * @param string $resource required resource name.
     */
    public function __construct( $bbt, $channel, $resource )
    {
        $this->bbt    = $bbt;
        $this->channel = $channel;
        $this->res    = $resource;
    }

    /**
     * Write (Persistent messages)
     * Writes data to this resource. 
     * This call will be signed to authenticate the calling user.
     *
     * @param mixed $data required the data value to write (persist).
     * @param integer $ts optional timestamp in milliseconds (since epoch). If this parameter is not given, it will be automatically added with a value equal to the local system time.
     * 
     * @return boolean true on success, raises an error or failure.
     */    
    public function write($data, $ts = null) {
        return $this->bbt->write($this->channel, $this->res, $data, $ts);
    }

    /**
     * Publish (Transient messages)
     * Publishes data to this resource.
     * This call will be signed to authenticate the calling user.
     *
     * @param mixed $data required the data value to publish (transient).
     * @param integer $ts optional timestamp in milliseconds (since epoch). If this parameter is not given, it will be automatically added with a value equal to the local system time.
     * 
     * @return boolean true on success, raises an error or failure.
     */
    public function publish($data, $ts = null) {
        return $this->bbt->publish($this->channel, $this->res, $data, $ts);
    }

    /**
     * Read
     * Reads data from the this resource.
     * If the owner is set (value different than null) the behaviour is Public Read (no authentication).
     * If the owner is null, the behaviour is authenticated read.      
     *
     * @param integer $limit optional number of records to return.
     * @param string $owner required the owner (username) of the resource to read from for public read. Null to read from the user's owned channel.
     * @param string $source optional indicates whether to read from live data or from historical statistics. Accepts ('live', 'hour-stats', 'day-stats').
     * @param string $timerange optional indicates the time range for the records to read. 
     *        Accepts ('Xhour', 'Xday, 'Xweek', 'Xmonth') with X a positive integer or 
     *        one of ('today', 'yesterday', 'current-week', 'last-week', 'current-month', 'last-month', 'ytd')
     * 
     * @return array of records (JSON) on success, raises an error or failure.
     */
    public function read($owner = null, $limit = null, $source = null, $timerange = null) {
        if($owner != null) {
            return $this->bbt->publicRead($owner, $this->channel, $this->res, $limit, $source, $timerange);
        }else {
            return $this->bbt->read($this->channel, $this->res, $limit, $source, $timerange);
        }
    }

    /**
     * Read
     * Reads the last inserted record. 
     *
     * @return array the last inserted record on success, raises an error or failure.
     */
    public function recentValue() {
        return ($this->bbt->read($this->channel, $this->res)[0]);
    }
}
?>
