<?php
	class GitHub_IRC
	{
		private $EventType;
		private $Payload;
		
		public function __construct( $EventType, $Payload )
		{
			$this->EventType = $EventType;
			$this->Payload = $Payload;
		}
		
		/**
		 * Parses GitHub's webhook payload and returns a formatted message
		 *
		 * @return string
		 */
		public function GetMessage( )
		{
			switch( $this->EventType )
			{
				case 'ping'        : return $this->FormatPingEvent( );
				case 'push'        : return $this->FormatPushEvent( );
				case 'issues'      : return $this->FormatIssuesEvent( );
				case 'pull_request': return $this->FormatPullRequestEvent( );
				default            : throw new Exception( 'Unsupported event type.' );
			}
		}
		
		private function GetDistinctCommits( )
		{
			$Commits = Array( );
			
			foreach( $this->Payload->commits as $Commit )
			{
				if( $Commit->distinct && !empty( $Commit->message ) )
				{
					$Commits[ ] = $Commit;
				}
			}
			
			return $Commits;
		}
		
		private function BranchName( )
		{
			$Ref = explode( '/', $this->Payload->ref );
			
			return $Ref[ 2 ];
		}
		
		private function BeforeSHA( )
		{
			return substr( $this->Payload->before, 0, 6 );
		}
		
		private function AfterSHA( )
		{
			return substr( $this->Payload->after, 0, 6 );
		}
		
		private function FormatRepoName( )
		{
			return "\00313" . $this->Payload->repository->name . "\017";
		}
		
		private function FormatBranch( $Branch )
		{
			return "\00306" . $Branch . "\017";
		}
		
		private function FormatName( $Name )
		{
			return "\00315" . $Name . "\017";
		}
		
		private function FormatAction( )
		{
			switch( $this->Payload->action )
			{
				case 'synchronize': return "\00312synchronized\017";
				case 'reopened'   : return "\00307" . $this->Payload->action . "\017";
				case 'force-pushed':
				case 'deleted'    :
				case 'closed'     : return "\00304" . $this->Payload->action . "\017";
				default           : return "\00309" . $this->Payload->action . "\017";
			}
		}
		
		private function FormatNumber( $Number )
		{
			return "\00309\002" . $Number . "\017";
		}
		
		private function FormatHash( $Hash )
		{
			return "\00314" . $Hash . "\017";
		}
		
		private function FormatURL( $URL )
		{
			// TODO: Add support for git.io
			
			return "\00302\037" . $URL . "\017";
		}
		
		/**
		 * Formats a push event
		 */
		private function FormatPushEvent( )
		{
			$DistinctCommits = $this->GetDistinctCommits( );
			$Num = count( $DistinctCommits );
			
			$Message = sprintf( '[%s] %s ',
				$this->FormatRepoName( ),
				$this->FormatName( $this->Payload->pusher->name )
			);
			
			if( $this->Payload->created )
			{
				if( substr( $this->Payload->ref, 0, 10 ) === 'refs/tags/' )
				{
					$Message .= sprintf( 'tagged %s at %s',
						$this->FormatBranch( $this->BranchName( ) ),
						isset( $this->Payload->base_ref ) ?
							$this->FormatBranch( $this->BranchName( ) ) :
							$this->FormatHash( $this->BeforeSHA( ) )
					);
				}
				else
				{
					$Message .= sprintf( 'created %s ', $this->FormatBranch( $this->BranchName( ) ) );
					
					if( isset( $this->Payload->base_ref ) )
					{
						$Ref = explode( '/', $this->Payload->base_ref );
						$Ref = $Ref[ 2 ];
						
						$Message .= sprintf( 'from %s', $this->FormatBranch( $Ref ) );
					}
					else if( $Num > 0 )
					{
						$Message .= sprintf( 'at %s', $this->FormatHash( $this->AfterSHA( ) ) );
					}
					
					if( $Num > 0 )
					{
						$Message .= sprintf( ' (+%s new commit%s)',
							$this->FormatNumber( $Num ),
							$Num === 1 ? '' : 's'
						);
					}
				}
			}
			else if( $this->Payload->deleted )
			{
				$this->Payload->action = 'deleted'; // Ssshhhh...
				
				$Message .= sprintf( '%s %s at %s',
					$this->FormatAction( ),
					$this->FormatBranch( $this->BranchName( ) ),
					$this->FormatHash( $this->BeforeSHA( ) )
				);
			}
			else if( $this->Payload->forced )
			{
				$this->Payload->action = 'force-pushed'; // Don't tell anyone!
				
				$Message .= sprintf( '%s %s from %s to %s',
					$this->FormatAction( ),
					$this->FormatBranch( $this->BranchName( ) ),
					$this->FormatHash( $this->BeforeSHA( ) ),
					$this->FormatHash( $this->AfterSHA( ) )
				);
			}
			else if( count( $this->Payload->commits ) > 0 && $Num === 0 )
			{
				if( isset( $this->Payload->base_ref ) )
				{
					$Ref = explode( '/', $this->Payload->base_ref );
					$Ref = $Ref[ 2 ];
					
					$Message .= sprintf( 'merged %s into %s',
						$this->FormatBranch( $Ref ),
						$this->FormatBranch( $this->BranchName( ) )
					);
				}
				else
				{
					$Message .= sprintf( 'fast-forwarded %s from %s to %s',
						$this->FormatBranch( $this->BranchName( ) ),
						$this->FormatHash( $this->BeforeSHA( ) ),
						$this->FormatHash( $this->AfterSHA( ) )
					);
				}
			}
			else
			{
				$Message .= sprintf( 'pushed %s new commit%s to %s',
					$this->FormatNumber( $Num ),
					$Num === 1 ? '' : 's',
					$this->FormatBranch( $this->BranchName( ) )
				);
			}
			
			$Message .= sprintf( ': %s', $this->FormatURL( $this->Payload->compare ) );
			
			// TODO: foreach $DistinctCommits
			// https://github.com/github/github-services/blob/master/lib/services/irc.rb#L262-L273
			
			return $Message;
		}
		
		/**
		 * Formats an issue event
		 */
		private function FormatIssuesEvent( )
		{
			return sprintf( '[%s] %s %s issue %s: %s. See %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( ),
							$this->FormatNumber( sprintf( '#%d', $this->Payload->issue->number ) ),
							$this->Payload->issue->title,
							$this->FormatURL( $this->Payload->issue->html_url )
			);
		}
		
		/**
		 * Formats a pull request event
		 */
		private function FormatPullRequestEvent( )
		{
			$BaseRef = explode( ':', $this->Payload->pull->base->label );
			$HeadRef = explode( ':', $this->Payload->pull->head->label );
			$BaseRef = end( $BaseRef );
			$HeadRef = end( $HeadRef );
			
			return sprintf( '[%s] %s %s pull request %s: %s (%s...%s). See %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( ),
							$this->FormatNumber( '#' . $this->Payload->pull->number ),
							$this->Payload->pull->title,
							$this->FormatBranch( $BaseRef ),
							$this->FormatBranch( $HeadRef ),
							$this->FormatURL( $this->Payload->pull->html_url )
			);
		}
		
		/**
		 * Formats a ping event
		 */
		private function FormatPingEvent( )
		{
			return "GitHub's Zen: \00312" . $this->Payload->zen . "\017 (hook worked!)";
		}
	}
