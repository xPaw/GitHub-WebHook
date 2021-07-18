<?php
declare(strict_types=1);

class IgnoredEventException extends Exception
{
	public string $EventName = '';
	
	public function __construct( string $Event )
	{
		$this->EventName = $Event;
		
		parent::__construct( 'Event type "' . $Event . '" is ignored by design due to spammy nature of the event.' );
	}
}
