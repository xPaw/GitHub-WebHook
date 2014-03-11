<?php
	Header( 'Content-Type: text/plain; charset=utf-8' );
	
	// Don't do this in production!
	ini_set( 'error_reporting', -1 );
	ini_set( 'display_errors', 1 );
	
	http_response_code( 500 );
	
	/* load the config */
	require __DIR__ . '/config.php';
	
	require __DIR__ . '/GitHub_WebHook.php';
	require __DIR__ . '/GitHub_IRC.php';
	
	$Socket = false;
	$Hook = new GitHub_WebHook( );
	
	try
	{
		$Hook->ProcessRequest( );
		
		// This check is optional, you can implement some secret GET param for example
		if( !$Hook->ValidateIPAddress() )
		{
			http_response_code( 401 );
			
			exit;
		}
		
		$RepositoryName = $Hook->GetFullRepositoryName();
		
		echo 'Received ' . $Hook->GetEventType() . ' in repository ' . $RepositoryName . PHP_EOL;
		//print_r( $Hook->GetPayload() );
		
		// Format IRC message
		$IRC = new GitHub_IRC( $Hook->GetEventType(), $Hook->GetPayload(), 'shorten_url' );
		$Message = $IRC->GetMessage();
		
		// Format irker payload
		$IrkerPayload = '';
		
		foreach( $Channels as $Channel )
		{
			if( !wild( $RepositoryName, $Channel ) )
			{
				continue;
			}
			
			foreach( $Channel as $Target )
			{
				$IrkerPayload .= json_encode( Array(
					'to'      => $Target,
					'privmsg' => $Message
				) ) . "\n";
			}
		}
		
		// Send to irker
		$Socket = socket_create( AF_INET, SOCK_STREAM, SOL_TCP );
		
		if( $Socket === false
		|| socket_connect( $Socket, IRKER_HOST, IRKER_PORT ) === false
		|| socket_write( $Socket, $IrkerPayload ) === false )
		{
			throw new Exception( 'Socket error: ' . socket_strerror( socket_last_error() ) );
		}
		
		echo 'Payload sent to irker' . PHP_EOL . $IrkerPayload . PHP_EOL;
		
		http_response_code( 202 );
	}
	catch( Exception $e )
	{
		echo 'Exception: ' . $e->getMessage() . PHP_EOL;
	}
	
	// Cleanup
	if( $Socket !== false )
	{
		socket_close( $Socket );
	}
	
	function wild( $string, $expression )
	{
		if( strpos( $expression, '*' ) === false )
		{
			return strcmp( $expression, $string ) === 0;
		}
		
		$expression = preg_quote( $expression, '/' );
		$expression = str_replace( '\*', '.*', $expression );
		
		return preg_match( '/^' . $expression . '$/', $string ) === 1;
	}
	
	// Taken from @meklu's gitmek-rcv
	function shorten_url($url) {
		$opts = array(
			"http" => array(
				"header" => "Content-type: application/x-www-form-urlencoded\r\n",
				"method" => "POST",
				"content" => http_build_query(array("url" => $url)),
			),
		);
		$ctx = stream_context_create($opts);
		$stream = @fopen("http://git.io", "r", false, $ctx);
		if ($stream === false) {
			/* damn it */
			return $url;
		}
		$md = stream_get_meta_data($stream);
		fclose($stream);
		$headers = $md["wrapper_data"];
		foreach($headers as $header) {
			$key = "Location: ";
			if (strpos($header, $key) === 0) {
				return substr($header, strlen($key));
			}
		}
		/* when all else fails, be stupid */
		return $url;
	}
