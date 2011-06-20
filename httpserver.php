<?php

/*
 * A simple standalone HTTP server for development that serves PHP scripts and static files.
 * Clients should subclass HTTPServer and override the route_request() method, at least.
 *
 * Copyright (c) 2011, Trust for Conservation Innovation
 */
 
require_once __DIR__."/httprequest.php";
require_once __DIR__."/httpresponse.php";

class HTTPServer
{
    /* 
     * The following public properties can be passed as options to the constructor: 
     */    
    public $port = 80;                      // TCP port number to listen on    
    public $cgi_env = array();              // associative array of additional environment variables to pass to php-cgi
    public $server_id = 'HTTPServer/0.1';   // identifier string to use in 'Server' header of HTTP response
    public $php_cgi = 'php-cgi';            // Path to php-cgi, if not in the PATH    
    
    /* 
     * Internal map of active client socket resource IDs to HTTPRequest objects
     */    
    private $requests = array(/* socket_id => HTTPRequest */);    
    
    function __construct($options = null)
    {
        if ($options)
        {
            foreach ($options as $k => $v)
            {
                $this->$k = $v;
            }
        }
    }
    
    /*  
     * Subclasses should override to route the current request to either a static file or PHP script
     * and return a HTTPResponse object. This function should call get_static_response() or
     * get_php_response(), as applicable.
     */
    function route_request($request)
    {
        return new HTTPResponse(500, "HTTPServer::route_request not implemented");
    }    
    
    /*
     * Subclasses could override to disallow other characters in path names
     */
    function is_allowed_uri($uri)
    {        
        return $uri[0] == '/'                   // all URIs should start with a /
            && strpos($uri, '..') === false     // prevent paths from escaping document root
            && !preg_match('#/\.#', $uri);      // disallow dotfiles
    }    
    
    /*
     * Subclasses could override to output a log entry in a particular format
     */    
    function get_log_line($request)
    {
        $response = $request->response;        
        $time = strftime("%H:%M:%S");
        
        // http://www.w3.org/Daemon/User/Config/Logging.html#common-logfile-format
        return "{$request->remote_addr} - - [$time] \"{$request->request_line}\" {$response->status} {$response->get_content_length()}\n";
    }      
    
    function bind_error()
    {
        error_log("Could not start a web server on port {$this->port}.");    
    }
    
    function run_forever()
    {    
        // provide some required/useful environment variables even if 'E' is not in variables_order
        $env_keys = array('HOME','OS','Path','PATHEXT','SystemRoot','TEMP','TMP');
        foreach ($env_keys as $key)
        {
            $_ENV[$key] = getenv($key);
        }
    
        set_time_limit(0);

        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
            
        if (@socket_bind($sock, 0, $this->port) == false)
        {
            $this->bind_error();
            return;
        }

        socket_listen($sock);

        echo "HTTP server listening on 0.0.0.0:{$this->port} (see http://localhost:{$this->port}/)...\n";    

        socket_set_nonblock($sock);        

        $requests =& $this->requests;
    
        while (true)
        {        
            $read = array();
            $write = array();
            foreach ($requests as $id => $request)
            {
                if (!$request->is_read_complete())
                {
                    $read[] = $request->socket;
                }
                else
                {
                    $write[] = $request->socket;
                }
            }            
            $read[] = $sock;            
            
            if (socket_select($read, $write, $except = null, null) < 1)
                continue;
                        
            if (in_array($sock, $read)) // new client connection
            {
                $client = socket_accept($sock);
                $requests[(int)$client] = new HTTPRequest($client);
                
                $key = array_search($sock, $read);
                unset($read[$key]);
            }
            
            foreach ($read as $client)
            {
                $this->read_socket($client);
            }
            
            foreach ($write as $client)
            {
                $this->write_socket($client);
            }
        }        
    }
    
    function write_socket($client)
    {
        $request = $this->requests[(int)$client];
        $response_buf =& $request->response_buf;     
        $len = @socket_write($client, $response_buf);
        if ($len === null)
        {
            $this->end_request($request);
        }
        else if ($len < strlen($response_buf))
        {
            $response_buf = substr($response_buf, $len);
        }
        else // done sending HTTP response
        {
            echo $this->get_log_line($request);
            
            if ($request->get_header('Connection') == 'close' || $request->http_version != 'HTTP/1.1')
            {
                $this->end_request($request);
            }
            else // HTTP Keep-Alive: expect another request on same client socket
            {                
                $this->requests[(int)$client] = new HTTPRequest($client);
            }
        }                
    }
    
    function read_socket($client)
    {
        $request = $this->requests[(int)$client];
        $data = @socket_read($client, 8092, PHP_BINARY_READ);
        
        if ($data === null || $data == '')
        {
            $this->end_request($request);
        }
        else
        {
            $request->add_data($data);
            
            if ($request->is_read_complete())
            {
                $response = $this->get_response($request);
                $request->set_response($response);
            }    
        }
    }
    
    function end_request($request)
    {
        @socket_close($request->socket);
        unset($this->requests[(int)$request->socket]);    
    }        
    
    function get_response($request)
    {
        $uri = $request->uri;

        if (!$this->is_allowed_uri($uri))
        {
            $response = new HTTPResponse(403, "Invalid URI $uri"); 
        }
        else
        {        
            $response = $this->route_request($request);        
        }
        
        $response->headers['Server'] = $this->server_id;
        
        return $response;
    }
       
    /*
     * Returns a HTTPResponse object for the static file at $local_path.
     */      
    function get_static_response($request, $local_path)
    {   
        if (is_file($local_path))
        {
            return new HTTPResponse(200, 
                file_get_contents($local_path),
                array(
                    'Content-Type' => static::get_mime_type($local_path),
                    'Cache-Control' => "max-age=8640000"
                )
            );
        }
        else if (is_dir($local_path))
        {
            return new HTTPResponse(403, "Directory listing not allowed");
        }
        else
        {
            return new HTTPResponse(404, "File not found");
        }    
    }        
            
    /*
     * Executes the PHP script in $script_filename using php-cgi, and returns 
     * a HTTPResponse object. $cgi_env_override can be set to an associative array 
     * to set or override any environment variables in the CGI process (e.g. PATH_INFO).
     */
    function get_php_response($request, $script_filename, $cgi_env_override = null)
    {        
        if (!is_file($script_filename))
        {
            return new HTTPResponse(404, "File not found");
        }    
        
        $content_length = $request->get_header('Content-Length');

        // see http://www.faqs.org/rfcs/rfc3875.html
        $cgi_env = array(
            'QUERY_STRING' => $request->query_string,
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => $request->request_uri,
            'REDIRECT_STATUS' => 200,
            'SCRIPT_FILENAME' => $script_filename,            
            'SCRIPT_NAME' => pathinfo($script_filename, PATHINFO_BASENAME),
            'SERVER_NAME' => $request->get_header('Host'),
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_SOFTWARE' => $this->server_id,
            'CONTENT_TYPE' => $request->get_header('Content-Type'),
            'CONTENT_LENGTH' => $content_length,            
            'REMOTE_ADDR' => $request->remote_addr,
        );                
        
        foreach ($request->headers as $name => $value)
        {        
            $name = str_replace('-','_', $name);
            $name = strtoupper($name);
            $cgi_env["HTTP_$name"] = $value;
        }
        
        if ($cgi_env_override)
        {
            foreach ($cgi_env_override as $name => $value)
            {
                $cgi_env[$name] = $value;
            }
        }

        if ($content_length)
        {
            $content_stream = tmpfile();
            fwrite($content_stream, $request->content);
            fseek($content_stream, 0);
        }
        else
        {        
            $content_stream = fopen("data://text/plain,", 'rb');
        }
        
        $descriptorspec = array(
           0 => $content_stream,
           1 => array('pipe', 'w'),
           2 => STDERR, 
        );
        
        $proc = proc_open($this->php_cgi, $descriptorspec, $pipes, 
            __DIR__, 
            array_merge($_ENV, $this->cgi_env, $cgi_env),
            array(
                'binary_pipes' => true,
                'bypass_shell' => true
            )
        );                        
        
        if (!is_resource($proc))
        {
            return new HTTPResponse(500, "Internal Server Error: php-cgi was not found");
        }
                
        ob_start();
        fpassthru($pipes[1]);
        $response_str = ob_get_clean();
        
        if (!$response_str)
        {
            return new HTTPResponse(500, "Internal Server Error: php-cgi did not return a response");
        }        

        $end_response_headers = strpos($response_str, "\r\n\r\n");
        
        $headers_str = substr($response_str, 0, $end_response_headers);

        $headers = static::parse_headers($headers_str);        
        
        $response = new HTTPResponse();                        
        
        // CGI process sends HTTP status as regular header
        if (isset($headers['Status']))
        {
            $response->status = (int) $headers['Status'];
            unset($headers['Status']);
        }
        $response->headers = $headers;                        
        $response->content = substr($response_str, $end_response_headers + 4);
        
        proc_close($proc);
        
        fclose($content_stream);
                
        return $response;
    }         

    static function parse_headers($headers_str)
    {
        $headers_arr = explode("\r\n", $headers_str);
                
        $headers = array();
        foreach ($headers_arr as $header_str)
        {
            $header_arr = explode(": ", $header_str, 2);
            $header_name = $header_arr[0];            
            $headers[$header_name] = $header_arr[1];
        }                
        return $headers;
    }                          
        
    static function get_mime_type($filename)
    {
        $pathinfo = pathinfo($filename);
        $extension = strtolower($pathinfo['extension']);
    
        return @static::$mime_types[$extension];
    }        
    
    /*
     * List of mime types for common file extensions
     * (c) Tyler Hall http://code.google.com/p/php-aws/
     * released under MIT License
     */
	static $mime_types = array("323" => "text/h323", "acx" => "application/internet-property-stream", "ai" => "application/postscript", "aif" => "audio/x-aiff", "aifc" => "audio/x-aiff", "aiff" => "audio/x-aiff",
        "asf" => "video/x-ms-asf", "asr" => "video/x-ms-asf", "asx" => "video/x-ms-asf", "au" => "audio/basic", "avi" => "video/quicktime", "axs" => "application/olescript", "bas" => "text/plain", "bcpio" => "application/x-bcpio", "bin" => "application/octet-stream", "bmp" => "image/bmp",
        "c" => "text/plain", "cat" => "application/vnd.ms-pkiseccat", "cdf" => "application/x-cdf", "cer" => "application/x-x509-ca-cert", "class" => "application/octet-stream", "clp" => "application/x-msclip", "cmx" => "image/x-cmx", "cod" => "image/cis-cod", "cpio" => "application/x-cpio", "crd" => "application/x-mscardfile",
        "crl" => "application/pkix-crl", "crt" => "application/x-x509-ca-cert", "csh" => "application/x-csh", "css" => "text/css", "dcr" => "application/x-director", "der" => "application/x-x509-ca-cert", "dir" => "application/x-director", "dll" => "application/x-msdownload", "dms" => "application/octet-stream", "doc" => "application/msword",
        "dot" => "application/msword", "dvi" => "application/x-dvi", "dxr" => "application/x-director", "eps" => "application/postscript", "etx" => "text/x-setext", "evy" => "application/envoy", "exe" => "application/octet-stream", "fif" => "application/fractals", "flr" => "x-world/x-vrml", "gif" => "image/gif",
        "gtar" => "application/x-gtar", "gz" => "application/x-gzip", "h" => "text/plain", "hdf" => "application/x-hdf", "hlp" => "application/winhlp", "hqx" => "application/mac-binhex40", "hta" => "application/hta", "htc" => "text/x-component", "htm" => "text/html", "html" => "text/html",
        "htt" => "text/webviewhtml", "ico" => "image/x-icon", "ief" => "image/ief", "iii" => "application/x-iphone", "ins" => "application/x-internet-signup", "isp" => "application/x-internet-signup", "jfif" => "image/pipeg", "jpe" => "image/jpeg", "jpeg" => "image/jpeg", "jpg" => "image/jpeg",
        "js" => "application/x-javascript", "latex" => "application/x-latex", "lha" => "application/octet-stream", "lsf" => "video/x-la-asf", "lsx" => "video/x-la-asf", "lzh" => "application/octet-stream", "m13" => "application/x-msmediaview", "m14" => "application/x-msmediaview", "m3u" => "audio/x-mpegurl", "man" => "application/x-troff-man",
        "mdb" => "application/x-msaccess", "me" => "application/x-troff-me", "mht" => "message/rfc822", "mhtml" => "message/rfc822", "mid" => "audio/mid", "mny" => "application/x-msmoney", "mov" => "video/quicktime", "movie" => "video/x-sgi-movie", "mp2" => "video/mpeg", "mp3" => "audio/mpeg",
        "mpa" => "video/mpeg", "mpe" => "video/mpeg", "mpeg" => "video/mpeg", "mpg" => "video/mpeg", "mpp" => "application/vnd.ms-project", "mpv2" => "video/mpeg", "ms" => "application/x-troff-ms", "mvb" => "application/x-msmediaview", "nws" => "message/rfc822", "oda" => "application/oda",
        "p10" => "application/pkcs10", "p12" => "application/x-pkcs12", "p7b" => "application/x-pkcs7-certificates", "p7c" => "application/x-pkcs7-mime", "p7m" => "application/x-pkcs7-mime", "p7r" => "application/x-pkcs7-certreqresp", "p7s" => "application/x-pkcs7-signature", "pbm" => "image/x-portable-bitmap", "pdf" => "application/pdf", "pfx" => "application/x-pkcs12",
        "pgm" => "image/x-portable-graymap", "pko" => "application/ynd.ms-pkipko", "pma" => "application/x-perfmon", "pmc" => "application/x-perfmon", "pml" => "application/x-perfmon", "pmr" => "application/x-perfmon", "pmw" => "application/x-perfmon", "png" => "image/png", "pnm" => "image/x-portable-anymap", "pot" => "application/vnd.ms-powerpoint", "ppm" => "image/x-portable-pixmap",
        "pps" => "application/vnd.ms-powerpoint", "ppt" => "application/vnd.ms-powerpoint", "prf" => "application/pics-rules", "ps" => "application/postscript", "pub" => "application/x-mspublisher", "qt" => "video/quicktime", "ra" => "audio/x-pn-realaudio", "ram" => "audio/x-pn-realaudio", "ras" => "image/x-cmu-raster", "rgb" => "image/x-rgb",
        "rmi" => "audio/mid", "roff" => "application/x-troff", "rtf" => "application/rtf", "rtx" => "text/richtext", "scd" => "application/x-msschedule", "sct" => "text/scriptlet", "setpay" => "application/set-payment-initiation", "setreg" => "application/set-registration-initiation", "sh" => "application/x-sh", "shar" => "application/x-shar",
        "sit" => "application/x-stuffit", "snd" => "audio/basic", "spc" => "application/x-pkcs7-certificates", "spl" => "application/futuresplash", "src" => "application/x-wais-source", "sst" => "application/vnd.ms-pkicertstore", "stl" => "application/vnd.ms-pkistl", "stm" => "text/html", "svg" => "image/svg+xml", "sv4cpio" => "application/x-sv4cpio",
        "sv4crc" => "application/x-sv4crc", "t" => "application/x-troff", "tar" => "application/x-tar", "tcl" => "application/x-tcl", "tex" => "application/x-tex", "texi" => "application/x-texinfo", "texinfo" => "application/x-texinfo", "tgz" => "application/x-compressed", "tif" => "image/tiff", "tiff" => "image/tiff",
        "tr" => "application/x-troff", "trm" => "application/x-msterminal", "tsv" => "text/tab-separated-values", "txt" => "text/plain", "uls" => "text/iuls", "ustar" => "application/x-ustar", "vcf" => "text/x-vcard", "vrml" => "x-world/x-vrml", "wav" => "audio/x-wav", "wcm" => "application/vnd.ms-works",
        "wdb" => "application/vnd.ms-works", "wks" => "application/vnd.ms-works", "wmf" => "application/x-msmetafile", "wps" => "application/vnd.ms-works", "wri" => "application/x-mswrite", "wrl" => "x-world/x-vrml", "wrz" => "x-world/x-vrml", "xaf" => "x-world/x-vrml", "xbm" => "image/x-xbitmap", "xla" => "application/vnd.ms-excel",
        "xlc" => "application/vnd.ms-excel", "xlm" => "application/vnd.ms-excel", "xls" => "application/vnd.ms-excel", "xlt" => "application/vnd.ms-excel", "xlw" => "application/vnd.ms-excel", "xof" => "x-world/x-vrml", "xpm" => "image/x-xpixmap", "xwd" => "image/x-xwindowdump", "z" => "application/x-compress", "zip" => "application/zip");    
}
