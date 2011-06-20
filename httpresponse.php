<?php

/*
 * Copyright (c) 2011, Trust for Conservation Innovation
 * Released under MIT license; see LICENSE.txt
 * http://github.com/youngj/httpserver
 */
 
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
            $headers['Content-Length'] = $this->get_content_length();
        }        
            
        $status_msg = static::$status_messages[$status];

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
    
    function get_content_length()
    {
        return strlen($this->content);
    }    
    
    static $status_messages = array(
        100 => "Continue",
        101 => "Switching Protocols",
        200 => "OK",
        201 => "Created",
        202 => "Accepted",
        203 => "Non-Authoritative Information",
        204 => "No Content",
        205 => "Reset Content",
        206 => "Partial Content",
        300 => "Multiple Choices",
        301 => "Moved Permanently",
        302 => "Found",
        303 => "See Other",
        304 => "Not Modified",
        305 => "Use Proxy",
        307 => "Temporary Redirect",
        400 => "Bad Request",
        401 => "Unauthorized",
        402 => "Payment Required",
        403 => "Forbidden",
        404 => "Not Found",
        405 => "Method Not Allowed",
        406 => "Not Acceptable",
        407 => "Proxy Authentication Required",
        408 => "Request Timeout",
        409 => "Conflict",
        410 => "Gone",
        411 => "Length Required",
        412 => "Precondition Failed",
        413 => "Request Entity Too Large",
        414 => "Request-URI Too Long",
        415 => "Unsupported Media Type",
        416 => "Requested Range Not Satisfiable",
        417 => "Expectation Failed",
        500 => "Internal Server Error",
        501 => "Not Implemented",
        502 => "Bad Gateway",
        503 => "Service Unavailable",
        504 => "Gateway Timeout",
        505 => "HTTP Version Not Supported",
    );
}