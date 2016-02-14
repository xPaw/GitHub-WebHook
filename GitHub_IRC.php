<?php
	class GitHubNotImplementedException extends Exception
	{
		public $EventName = '';
		
		public function __construct( $Event )
		{
			$this->EventName = $Event;
			
			parent::__construct( 'Unsupported event type "' . $Event . '".' );
		}
	}
	
	class GitHubIgnoredEventException extends Exception
	{
		public $EventName = '';
		
		public function __construct( $Event )
		{
			$this->EventName = $Event;
			
			parent::__construct( 'Event type "' . $Event . '" is ignored by design due to spammy nature of the event.' );
		}
	}
	
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
				$Ref = explode( '/', $this->Payload->ref, 3 );
				
				if( isset( $Ref[ 2 ] ) )
				{
					$this->Payload->ref_name = $Ref[ 2 ];
				}
			}
			
			if( !isset( $this->Payload->base_ref_name ) && isset( $this->Payload->base_ref ) )
			{
				$Ref = explode( '/', $this->Payload->base_ref, 3 );
				
				if( isset( $Ref[ 2 ] ) )
				{
					$this->Payload->base_ref_name = $Ref[ 2 ];
				}
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
				case 'gollum'        : return $this->FormatGollumEvent( );
				case 'release'       : return $this->FormatReleaseEvent( );
				case 'repository'    : return $this->FormatRepositoryEvent( );
				case 'pull_request'  : return $this->FormatPullRequestEvent( );
				case 'issue_comment' : return $this->FormatIssueCommentEvent( );
				case 'commit_comment': return $this->FormatCommitCommentEvent( );
				case 'pull_request_review_comment': return $this->FormatPullRequestReviewCommentEvent( );
				
				// Spammy events that we do not care about
				case 'fork'          :
				case 'watch'         :
				case 'status'        : throw new GitHubIgnoredEventException( $this->EventType );
			}
			
			throw new GitHubNotImplementedException( $this->EventType );
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
				if( isset( $Commit->distinct ) && !$Commit->distinct )
				{
					continue;
				}
				
				if( !empty( $Commit->message ) )
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
		
		private function FormatAction( $Action = false )
		{
			if( $Action === false )
			{
				$Action = $this->Payload->action;
			}
			
			switch( $Action )
			{
				case 'synchronize': return "\00311synchronized\017";
				case 'created'    :
				case 'reopened'   : return "\00307" . $Action . "\017";
				case 'force-pushed':
				case 'deleted'    :
				case 'closed without merging':
				case 'closed'     : return "\00304" . $Action . "\017";
				default           : return "\00309" . $Action . "\017";
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
		
		private function ShortenAndFormatURL( $URL )
		{
			if( $this->URLShortener !== null )
			{
				$URL = call_user_func( $this->URLShortener, $URL );
			}
			
			return $this->FormatURL( $URL );
		}
		
		private function FormatURL( $URL )
		{
			return "\00302\037" . $URL . "\017";
		}
		
		private function ShortMessage( $Message )
		{
			$Message = trim( $Message );
			$NewMessage = Explode( "\n", $Message, 2 );
			$NewMessage = $NewMessage[ 0 ];
			
			if( strlen( $NewMessage ) > 100 )
			{
				$NewMessage = substr( $Message, 0, 100 );
			}
			
			if( $NewMessage !== $Message )
			{
				// Tidy ellipsis
				if( substr( $NewMessage, -3 ) === '...' )
				{
					$NewMessage = substr( $NewMessage, 0, -3 ) . '…';
				}
				else if( substr( $NewMessage, -1 ) !== '…' )
				{
					$NewMessage .= '…';
				}
			}
			
			return $NewMessage;
		}
		
		/**
		 * Formats a push event
		 * See https://developer.github.com/v3/activity/events/types/#pushevent
		 */
		private function FormatPushEvent( )
		{
			$DistinctCommits = $this->GetDistinctCommits( );
			$Num = count( $DistinctCommits );
			
			$Message = sprintf( '[%s] %s ',
				$this->FormatRepoName( ),
				$this->FormatName( $this->Payload->pusher->name )
			);
			
			if( isset( $this->Payload->created ) && $this->Payload->created )
			{
				if( substr( $this->Payload->ref, 0, 10 ) === 'refs/tags/' )
				{
					$Message .= sprintf( 'tagged %s at %s',
						$this->FormatBranch( $this->Payload->ref_name ),
						isset( $this->Payload->base_ref ) ?
							$this->FormatBranch( $this->Payload->base_ref_name ) :
							$this->FormatHash( $this->AfterSHA( ) )
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
			else if( isset( $this->Payload->deleted ) && $this->Payload->deleted )
			{
				$this->Payload->action = 'deleted'; // Ssshhhh...
				
				$Message .= sprintf( '%s %s at %s',
					$this->FormatAction( ),
					$this->FormatBranch( $this->Payload->ref_name ),
					$this->FormatHash( $this->BeforeSHA( ) )
				);
			}
			else if( isset( $this->Payload->forced ) && $this->Payload->forced )
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
			
			$URL = isset( $this->Payload->compare_url ) ? $this->Payload->compare_url : $this->Payload->compare;
			
			if( $Num === 1 )
			{
				$URL = $this->Payload->commits[ 0 ]->url;
			}
			
			$Message .= sprintf( ': %s', $this->ShortenAndFormatURL( $URL ) );
			
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
			
			if( !isset( $this->Payload->repository->default_branch ) )
			{
				$this->Payload->repository->default_branch = 'master';
			}
			
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
					$this->FormatName( isset( $Commit->author->username ) ? $Commit->author->username : $Commit->author->name ),
					$this->ShortMessage( $Commit->message )
				);
			}
			
			return $Message;
		}
		
		/**
		 * Formats an issue event
		 * See https://developer.github.com/v3/activity/events/types/#issuesevent
		 */
		private function FormatIssuesEvent( )
		{
			if( $this->Payload->action === 'labeled'
			||  $this->Payload->action === 'unlabeled'
			||  $this->Payload->action === 'assigned'
			||  $this->Payload->action === 'unassigned' )
			{
				return '';
			}
			
			return sprintf( '[%s] %s %s issue %s: %s. %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( ),
							$this->FormatNumber( sprintf( '#%d', $this->Payload->issue->number ) ),
							$this->Payload->issue->title,
							$this->ShortenAndFormatURL( $this->Payload->issue->html_url )
			);
		}
		
		/**
		 * Formats a pull request event
		 * See https://developer.github.com/v3/activity/events/types/#pullrequestevent
		 */
		private function FormatPullRequestEvent( )
		{
			if( $this->Payload->action === 'labeled'
			||  $this->Payload->action === 'unlabeled'
			||  $this->Payload->action === 'assigned'
			||  $this->Payload->action === 'unassigned' )
			{
				return '';
			}
			
			return sprintf( '[%s] %s %s pull request %s%s: %s. %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( ),
							$this->FormatNumber( '#' . $this->Payload->pull_request->number ),
							$this->Payload->action === 'merged' ?
								( ' from ' . $this->FormatName( $this->Payload->pull_request->user->login ) . ' to ' . $this->FormatBranch( $this->Payload->pull_request->base->ref ) ) :
								'',
							$this->Payload->pull_request->title,
							$this->ShortenAndFormatURL( $this->Payload->pull_request->html_url )
			);
		}
		
		/**
		 * Formats a release event
		 * See https://developer.github.com/v3/activity/events/types/#releaseevent
		 */
		private function FormatReleaseEvent( )
		{
			return sprintf( '[%s] %s %s a %srelease %s: %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( ),
							$this->Payload->release->prerelease ? 'pre-' : '',
							$this->FormatBranch( empty( $this->Payload->release->name ) ? $this->Payload->release->tag_name : $this->Payload->release->name ),
							$this->ShortenAndFormatURL( $this->Payload->release->html_url )
			);
		}
		
		/**
		 * Formats a commit comment event
		 * See https://developer.github.com/v3/activity/events/types/#commitcommentevent
		 */
		private function FormatCommitCommentEvent( )
		{
			return sprintf( '[%s] %s commented on commit %s. %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatHash( substr( $this->Payload->comment->commit_id, 0, 6 ) ),
							$this->ShortenAndFormatURL( $this->Payload->comment->html_url )
			);
		}
		
		/**
		 * Formats a issue comment event
		 * See https://developer.github.com/v3/activity/events/types/#issuecommentevent
		 */
		private function FormatIssueCommentEvent( )
		{
			return sprintf( '[%s] %s commented on issue %s: %s. %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatNumber( '#' . $this->Payload->issue->number ),
							$this->Payload->issue->title,
							$this->ShortenAndFormatURL( $this->Payload->comment->html_url )
			);
		}
		
		/**
		 * Formats a pull request review comment event
		 * See https://developer.github.com/v3/activity/events/types/#pullrequestreviewcommentevent
		 */
		private function FormatPullRequestReviewCommentEvent( )
		{
			return sprintf( '[%s] %s reviewed pull request %s at %s. %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatNumber( '#' . $this->Payload->pull_request->number ),
							$this->FormatHash( substr( $this->Payload->comment->commit_id, 0, 6 ) ),
							$this->ShortenAndFormatURL( $this->Payload->comment->html_url )
			);
		}
		
		/**
		 * Formats a release event
		 * See https://developer.github.com/v3/activity/events/types/#releaseevent
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
		 * Formats a gollum event (wiki)
		 * See https://developer.github.com/v3/activity/events/types/#gollumevent
		 */
		private function FormatGollumEvent( )
		{
			$Message = '';
			
			foreach( $this->Payload->pages as $Page )
			{
				if( !empty( $Message ) )
				{
					$Message .= "\n";
				}
				
				$Message .= sprintf( "[%s] %s %s %s: %s%s",
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( $Page->action ),
							$Page->title,
							empty( $Page->summary ) ? '' : ( $Page->summary . ' ' ),
							$this->ShortenAndFormatURL( $Page->html_url )
				);
			}
			
			return $Message;
		}
		
		/**
		 * Formats a ping event
		 * See https://developer.github.com/webhooks/#ping-event
		 */
		private function FormatPingEvent( )
		{
			return sprintf( '[%s] Hook %s worked! Zen: %s',
							$this->FormatRepoName( ),
							$this->FormatHash( $this->Payload->hook->id ),
							$this->FormatName( $this->Payload->zen )
			);
		}
		
		/**
		 * Format a public event. Without a doubt: the best GitHub event
		 * See https://developer.github.com/v3/activity/events/types/#publicevent
		 */
		private function FormatPublicEvent( )
		{
			return sprintf( '[%s] is now open source and available to everyone at %s (You\'re the best %s!)',
							$this->FormatRepoName( ),
							$this->FormatURL( $this->Payload->repository->html_url ),
							$this->FormatName( $this->Payload->sender->login )
			);
		}
		
		/**
		 * Triggered when a repository is created.
		 * See https://developer.github.com/v3/activity/events/types/#repositoryevent
		 */
		private function FormatRepositoryEvent( )
		{
			return sprintf( '[%s] %s %s this repository. %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( ),
							$this->FormatURL( $this->Payload->repository->html_url )
			);
		}
	}
