<?php

class HTTPResponse
{
    public $status;     // HTTP status code
    public $content;    // response body        
    public $headers;    // associative array of HTTP headers    
    
    function __construct($status = 200, $content = '', $headers = null)
    {
        $this->status = $status;
        $this->content = $content;
        $this->headers = $headers ?: array();
    }        

    function render()
    {
        $headers = $this->headers;
        $status = $this->status;
        $content = $this->content;

        if (!isset($headers['Content-Length']))
        {
            $headers['Content-Length'] = strlen($content);
        }        
            
        $status_msg = static::$messages[$status];

        ob_start();
        
        echo "HTTP/1.1 $status $status_msg\r\n";
        foreach ($headers as $name => $value)
        {
            echo "$name: $value\r\n";
        }
        echo "\r\n";
        echo $content;
        
        return ob_get_clean();
    }
    
    /* 
     * HTTP status codes and messages originally from Kohana Request class
     * (c) 2007-2010, Kohana Team, 
     * released under BSD-style license in vendors/kohana_license.txt
     */
    public static $messages = array(
        // Informational 1xx
        100 => 'Continue',
        101 => 'Switching Protocols',

        // Success 2xx
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',

        // Redirection 3xx
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found', // 1.1
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        // 306 is deprecated but reserved
        307 => 'Temporary Redirect',

        // Client Error 4xx
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',

        // Server Error 5xx
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        507 => 'Insufficient Storage',
        509 => 'Bandwidth Limit Exceeded'
    );    
}