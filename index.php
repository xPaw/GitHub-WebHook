<?php
	Header( 'Content-Type: text/plain; charset=utf-8' );
	
	// Don't do this in production!
	ini_set( 'error_reporting', -1 );
	ini_set( 'display_errors', 1 );
	
	require __DIR__ . '/GitHub_WebHook.php';
	require __DIR__ . '/GitHub_IRC.php';
	
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
		
		// This check is optional, checks if your hook secret matches
		if( !$Hook->ValidateHubSignature( 'My secret key' ) )
		{
			http_response_code( 401 );
			
			exit;
		}
		
		echo 'Received ' . $Hook->GetEventType() . ' in repository ' . $Hook->GetFullRepositoryName() . PHP_EOL;
		//var_dump( $Hook->GetPayload() );
		
		http_response_code( 202 );
	}
	catch( Exception $e )
	{
		echo 'Exception: ' . $e->getMessage() . PHP_EOL;
		
		http_response_code( 500 );
		
		exit();
	}
	
	try
	{
		$IRC = new GitHub_IRC( $Hook->GetEventType(), $Hook->GetPayload(), 'shorten_url' );
		
		var_dump( $IRC->GetMessage() ); // Optional
		
	}
	catch( Exception $e )
	{
		echo 'Exception: ' . $e->getMessage() . PHP_EOL;
		
		http_response_code( 500 );
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
