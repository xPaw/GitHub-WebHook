<?php
	class GitHub_IRC
	{
		private $EventType;
		private $Payload;
		private $URLShortener;
		
		public function __construct( $EventType, $Payload, $URLShortener = null )
		{
			$this->EventType = $EventType;
			$this->Payload = $Payload;
			$this->URLShortener = $URLShortener;
			
			// ref_name is not always available, apparently, we make sure it is
			if( !isset( $this->Payload->ref_name ) && isset( $this->Payload->ref ) )
			{
				$Ref = explode( '/', $this->Payload->ref );
				
				$this->Payload->ref_name = $Ref[ 2 ];
			}
			
			if( !isset( $this->Payload->base_ref_name ) && isset( $this->Payload->base_ref ) )
			{
				$Ref = explode( '/', $this->Payload->base_ref );
				
				$this->Payload->base_ref_name = $Ref[ 2 ];
			}
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
				case 'ping'          : return $this->FormatPingEvent( );
				case 'push'          : return $this->FormatPushEvent( );
				case 'public'        : return $this->FormatPublicEvent( );
				case 'issues'        : return $this->FormatIssuesEvent( );
				case 'member'        : return $this->FormatMemberEvent( );
				case 'release'       : return $this->FormatReleaseEvent( );
				case 'pull_request'  : return $this->FormatPullRequestEvent( );
				case 'issue_comment' : return $this->FormatIssueCommentEvent( );
				case 'commit_comment': return $this->FormatCommitCommentEvent( );
				case 'pull_request_review_comment': return $this->FormatPullRequestReviewCommentEvent( );
				default              : throw new Exception( 'Unsupported event type "' . $this->EventType . '".' );
			}
		}
		
		/**
		 * Returns distinct commits which have non-empty commit messages
		 *
		 * @return array
		 */
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
			return "\00310" . $this->Payload->repository->name . "\017";
		}
		
		private function FormatBranch( $Branch )
		{
			return "\00306" . $Branch . "\017";
		}
		
		private function FormatName( $Name )
		{
			return "\00312" . $Name . "\017";
		}
		
		private function FormatAction( )
		{
			switch( $this->Payload->action )
			{
				case 'synchronize': return "\00311synchronized\017";
				case 'reopened'   : return "\00307" . $this->Payload->action . "\017";
				case 'force-pushed':
				case 'deleted'    :
				case 'closed without merging':
				case 'closed'     : return "\00304" . $this->Payload->action . "\017";
				default           : return "\00309" . $this->Payload->action . "\017";
			}
		}
		
		private function FormatNumber( $Number )
		{
			return "\00312\002" . $Number . "\017";
		}
		
		private function FormatHash( $Hash )
		{
			return "\00314" . $Hash . "\017";
		}
		
		private function FormatURL( $URL, $IgnoreURLShortener = false )
		{
			if( $this->URLShortener !== null && !$IgnoreURLShortener )
			{
				$URL = call_user_func( $this->URLShortener, $URL );
			}
			
			return "\00302\037" . $URL . "\017";
		}
		
		private function ShortMessage( $Message )
		{
			$NewMessage = Explode( "\n", $Message, 2 );
			$NewMessage = $NewMessage[ 0 ];
			
			if( $NewMessage !== $Message )
			{
				$NewMessage .= '...';
			}
			
			return $NewMessage;
		}
		
		/**
		 * Formats a push event
		 * See http://developer.github.com/v3/activity/events/types/#pushevent
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
						$this->FormatBranch( $this->Payload->ref_name ),
						isset( $this->Payload->base_ref ) ?
							$this->FormatBranch( $this->Payload->base_ref_name ) :
							$this->FormatHash( $this->BeforeSHA( ) )
					);
				}
				else
				{
					$Message .= sprintf( 'created %s ', $this->FormatBranch( $this->Payload->ref_name ) );
					
					if( isset( $this->Payload->base_ref ) )
					{
						$Message .= sprintf( 'from %s', $this->FormatBranch( $this->Payload->base_ref_name ) );
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
					$this->FormatBranch( $this->Payload->ref_name ),
					$this->FormatHash( $this->BeforeSHA( ) )
				);
			}
			else if( $this->Payload->forced )
			{
				$this->Payload->action = 'force-pushed'; // Don't tell anyone!
				
				$Message .= sprintf( '%s %s from %s to %s',
					$this->FormatAction( ),
					$this->FormatBranch( $this->Payload->ref_name ),
					$this->FormatHash( $this->BeforeSHA( ) ),
					$this->FormatHash( $this->AfterSHA( ) )
				);
			}
			else if( $Num === 0 && count( $this->Payload->commits ) > 0 )
			{
				if( isset( $this->Payload->base_ref ) )
				{
					$Message .= sprintf( 'merged %s into %s',
						$this->FormatBranch( $this->Payload->base_ref_name ),
						$this->FormatBranch( $this->Payload->ref_name )
					);
				}
				else
				{
					$Message .= sprintf( 'fast-forwarded %s from %s to %s',
						$this->FormatBranch( $this->Payload->ref_name ),
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
					$this->FormatBranch( $this->Payload->ref_name )
				);
			}
			
			$URL = $this->Payload->compare;
			
			if( $Num === 1 )
			{
				$URL = $this->Payload->commits[ 0 ]->url;
			}
			
			$Message .= sprintf( ': %s', $this->FormatURL( $URL ) );
			
			if( $Num > 0 )
			{
				// Only print last commit
				$Commit = array_pop( $DistinctCommits );
				$DistinctCommits = Array( $Commit );
				
				$Message .= $this->FormatCommits( $DistinctCommits );
				
				if( $Num > 1 )
				{
					$Num--;
					
					$Message .= sprintf( ' (and %s more commit%s)',
						$this->FormatNumber( $Num ),
						$Num === 1 ? '' : 's'
					);
				}
			}
			
			return $Message;
		}
		
		/**
		 * Formats commits
		 */
		private function FormatCommits( $Commits )
		{
			$Message = '';
			
			$Branch = $this->Payload->ref_name;
			
			// Only display branch name if it's not default branch
			if( $Branch !== $this->Payload->repository->default_branch )
			{
				$Prefix = sprintf( "\n[%s/%s]", $this->FormatRepoName( ), $this->FormatBranch( $Branch ) );
			}
			else
			{
				$Prefix = sprintf( "\n[%s]", $this->FormatRepoName( ) );
			}
			
			foreach( $Commits as $Commit )
			{
				$Message .= sprintf( '%s %s %s: %s',
					$Prefix,
					$this->FormatHash( substr( $Commit->id, 0, 6 ) ),
					$this->FormatName( $Commit->author->username ),
					$this->ShortMessage( $Commit->message )
				);
			}
			
			return $Message;
		}
		
		/**
		 * Formats an issue event
		 * See http://developer.github.com/v3/activity/events/types/#issuesevent
		 */
		private function FormatIssuesEvent( )
		{
			return sprintf( '[%s] %s %s issue %s%s: %s. See %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( ),
							$this->FormatNumber( sprintf( '#%d', $this->Payload->issue->number ) ),
							( $this->Payload->action === 'labeled' ? ( ' as ' . $this->FormatName( $this->Payload->label->name ) ) : '' ),
							$this->Payload->issue->title,
							$this->FormatURL( $this->Payload->issue->html_url )
			);
		}
		
		/**
		 * Formats a pull request event
		 * See http://developer.github.com/v3/activity/events/types/#pullrequestevent
		 */
		private function FormatPullRequestEvent( )
		{
			if( $this->Payload->action === 'closed' )
			{
				if( $this->Payload->pull_request->merged === true )
				{
					$this->Payload->action = 'merged';
				}
				else
				{
					$this->Payload->action = 'closed without merging';
				}
			}
			
			return sprintf( '[%s] %s %s pull request %s%s: %s. See %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( ),
							$this->FormatNumber( '#' . $this->Payload->pull_request->number ),
							$this->Payload->action === 'merged' ?
								( ' from ' . $this->FormatName( $this->Payload->pull_request->user->login ) . ' to ' . $this->FormatBranch( $this->Payload->pull_request->base->ref ) ) :
								'',
							$this->Payload->pull_request->title,
							$this->FormatURL( $this->Payload->pull_request->html_url )
			);
		}
		
		/**
		 * Formats a release event
		 * See http://developer.github.com/v3/activity/events/types/#releaseevent
		 */
		private function FormatReleaseEvent( )
		{
			return sprintf( '[%s] %s %s a %srelease %s: %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( ),
							$this->Payload->release->prerelease ? 'pre' : '',
							$this->Payload->release->name,
							$this->FormatURL( $this->Payload->release->html_url )
			);
		}
		
		/**
		 * Formats a commit comment event
		 * See https://developer.github.com/v3/activity/events/types/#commitcommentevent
		 */
		private function FormatCommitCommentEvent( )
		{
			return sprintf( '[%s] %s commented on commit %s: %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatHash( substr( $this->Payload->comment->commit_id, 0, 6 ) ),
							$this->FormatURL( $this->Payload->comment->html_url )
			);
		}
		
		/**
		 * Formats a commit comment event
		 * See https://developer.github.com/v3/activity/events/types/#commitcommentevent
		 */
		private function FormatIssueCommentEvent( )
		{
			return sprintf( '[%s] %s commented on issue %s: %s. See %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatNumber( '#' . $this->Payload->issue->number ),
							$this->Payload->issue->title,
							$this->FormatURL( $this->Payload->comment->html_url )
			);
		}
		
		/**
		 * Formats a commit comment event
		 * See https://developer.github.com/v3/activity/events/types/#commitcommentevent
		 */
		private function FormatPullRequestReviewCommentEvent( )
		{
			if( preg_match( '/\/(\d+)$/', $this->Payload->comment->pull_request_url, $Number ) === 1 )
			{
				$Number = $Number[ 1 ];
			}
			else
			{
				$Number = -1;
			}
			
			return sprintf( '[%s] %s commented on pull request %s %s: %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatNumber( '#' . $Number ),
							$this->FormatHash( substr( $this->Payload->comment->commit_id, 0, 6 ) ),
							$this->FormatURL( $this->Payload->comment->html_url )
			);
		}
		
		/**
		 * Formats a release event
		 * See http://developer.github.com/v3/activity/events/types/#releaseevent
		 */
		private function FormatMemberEvent( )
		{
			return sprintf( '[%s] %s %s %s as a collaborator',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( ),
							$this->FormatName( $this->Payload->member->login )
			);
		}
		
		/**
		 * Formats a ping event
		 * See http://developer.github.com/webhooks/#ping-event
		 */
		private function FormatPingEvent( )
		{
			return sprintf( 'Hook %s worked! Zen: %s',
							$this->FormatHash( $this->Payload->hook->id ),
							$this->FormatName( $this->Payload->zen )
			);
		}
		
		/**
		 * Format a public event. Without a doubt: the best GitHub event
		 * See https://developer.github.com/v3/activity/events/types/#publicevent
		 */
		private static FormatPublicEvent( )
		{
			return sprintf( '[%s] is now open source and available to everyone at %s (You\'re the best %s!)',
							$this->FormatRepoName( ),
							$this->FormatURL( $this->Payload->repository->html_url, true ),
							$this->FormatName( $this->Payload->sender->login )
			);
		}
	}
