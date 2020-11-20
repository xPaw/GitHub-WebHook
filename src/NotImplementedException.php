<?php

class NotImplementedException extends Exception
{
	public string $EventName = '';
	
	public function __construct( string $Event, ?string $Action = null )
	{
		$this->EventName = $Event;
		
		if( $Action !== null )
		{
			$Message = 'Unsupported action type "' . $Action . '" in event type';
		}
		else
		{
			$Message = 'Unsupported event type';
		}
		
		parent::__construct( $Message . ' "' . $Event . '".' );
	}
}
