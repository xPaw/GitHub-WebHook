<?php
declare(strict_types=1);

class IrcConverter extends BaseConverter
{
	/**
	 * Parses GitHub's webhook payload and returns a formatted message
	 *
	 * @return string
	 */
	public function GetMessage( ) : string
	{
		switch( $this->EventType )
		{
			case 'ping'          : return $this->FormatPingEvent( );
			case 'push'          : return $this->FormatPushEvent( );
			case 'delete'        : return $this->FormatDeleteEvent( );
			case 'discussion'    : return $this->FormatDiscussionEvent( );
			case 'discussion_comment': return $this->FormatDiscussionCommentEvent( );
			case 'public'        : return $this->FormatPublicEvent( );
			case 'issues'        : return $this->FormatIssuesEvent( );
			case 'member'        : return $this->FormatMemberEvent( );
			case 'gollum'        : return $this->FormatGollumEvent( );
			case 'package'       : return $this->FormatPackageEvent( );
			case 'project'       : return $this->FormatProjectEvent( );
			case 'release'       : return $this->FormatReleaseEvent( );
			case 'milestone'     : return $this->FormatMilestoneEvent( );
			case 'repository'    : return $this->FormatRepositoryEvent( );
			case 'pull_request'  : return $this->FormatPullRequestEvent( );
			case 'issue_comment' : return $this->FormatIssueCommentEvent( );
			case 'commit_comment': return $this->FormatCommitCommentEvent( );
			case 'pull_request_review': return $this->FormatPullRequestReviewEvent( );
			case 'pull_request_review_comment': return $this->FormatPullRequestReviewCommentEvent( );
			case 'repository_vulnerability_alert': return $this->FormatRepositoryVulnerabilityAlertEvent( );
			
			// Spammy events that we do not care about
			case 'fork'          :
			case 'watch'         :
			case 'star'          :
			case 'status'        : throw new IgnoredEventException( $this->EventType );
		}
		
		throw new NotImplementedException( $this->EventType );
	}
	
	private function FormatRepoName( ) : string
	{
		return "\00310" . $this->Payload->repository->name . "\017";
	}
	
	private function FormatBranch( string $Branch ) : string
	{
		return "\00306" . $this->InsertZWJ( $Branch ) . "\017";
	}
	
	private function FormatName( string $Name ) : string
	{
		return "\00312" . $this->InsertZWJ( $Name ) . "\017";
	}
	
	private function InsertZWJ( string $String ) : string
	{
		return substr( $String, 0, 1 ) . "\u{200d}" . substr( $String, 1 );
	}

	private function FormatAction( ?string $Action = null ) : string
	{
		if( $Action === null )
		{
			$Action = $this->Payload->action;
		}
		
		switch( $Action )
		{
			case 'created'    :
			case 'resolved'   :
			case 'reopened'   :
				return "\00307" . $Action . "\017";

			case 'closed'     :
			case 'merged'     :
				return "\00313" . $Action . "\017";

			case 'locked'     :
			case 'deleted'    :
			case 'dismissed'  :
			case 'unpublished':
			case 'force-pushed':
			case 'requested changes':
			case 'closed without merging':
				return "\00304" . $Action . "\017";

			default           :
				return "\00309" . $Action . "\017";
		}
	}
	
	private function FormatNumber( string $Number ) : string
	{
		return "\00312\002" . $Number . "\017";
	}
	
	private function FormatHash( string $Hash ) : string
	{
		return "\00314" . $Hash . "\017";
	}
	
	private function FormatURL( string $URL ) : string
	{
		return "\00302" . $URL . "\017";
	}
	
	private function ShortMessage( string $Message, int $Limit = 100 ) : string
	{
		$Message = trim( $Message );
		$NewMessage = Explode( "\n", $Message, 2 );
		$NewMessage = $NewMessage[ 0 ];
		
		if( strlen( $NewMessage ) > $Limit )
		{
			$NewMessage = substr( $Message, 0, $Limit );
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
	private function FormatPushEvent( ) : string
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
				$Message .= sprintf( 'created %s', $this->FormatBranch( $this->Payload->ref_name ) );
				
				if( isset( $this->Payload->base_ref ) )
				{
					$Message .= sprintf( ' from %s', $this->FormatBranch( $this->Payload->base_ref_name ) );
				}
				else if( $Num > 0 )
				{
					$Message .= sprintf( ' at %s', $this->FormatHash( $this->AfterSHA( ) ) );
				}
				
				if( $Num > 0 )
				{
					$Message .= sprintf( ' (+%s new commit%s)',
						$this->FormatNumber( (string)$Num ),
						$Num === 1 ? '' : 's'
					);
				}
			}
		}
		else if( isset( $this->Payload->deleted ) && $this->Payload->deleted )
		{
			throw new NotImplementedException( $this->EventType, 'deleted (use DeleteEvent if needed)' );
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
				$this->FormatNumber( (string)$Num ),
				$Num === 1 ? '' : 's',
				$this->FormatBranch( $this->Payload->ref_name )
			);
		}
		
		if( $this->Payload->forced )
		{
			// GitHub supports displaying proper diffs for force pushes
			// but it only appears to work if the diff url has full hashes
			// so we construct the url ourselves, instead of using the url in the payload
			// Note: this uses ".." instead of "..." to force github to actually display changes between the commits
			// and not the entire diff of the force push
			$URL = "{$this->Payload->repository->url}/compare/{$this->Payload->before}..{$this->Payload->after}";
		}
		else if( $Num === 1 )
		{
			// If there's only one distinct commit, link to it directly
			$URL = $this->Payload->head_commit->url;
		}
		else
		{
			$URL = $this->Payload->compare;
		}
		
		if( $Num > 0 )
		{
			$CommitMessages = [];

			while( --$Num >= 0 )
			{
				$CommitMessages[] = $this->ShortMessage( $DistinctCommits[ $Num ]->message, 50 );
			}

			$CommitMessages = $this->ShortMessage( implode( $this->FormatHash( ' | ' ), $CommitMessages ), 200 );
			
			$Message .= sprintf( ': %s', $CommitMessages );
		}

		$Message .= ' ' . $this->FormatURL( $URL );

		return $Message;
	}
	
	/**
	 * Formats a deletion event
	 * See https://developer.github.com/v3/activity/events/types/#deleteevent
	 */
	private function FormatDeleteEvent( ) : string
	{
		if( $this->Payload->ref_type !== 'tag'
		&&  $this->Payload->ref_type !== 'branch' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->ref_type );
		}
		
		$this->Payload->action = 'deleted';
		
		return sprintf( '[%s] %s %s %s %s',
			$this->FormatRepoName( ),
			$this->FormatName( $this->Payload->sender->login ),
			$this->FormatAction( ),
			$this->Payload->ref_type,
			$this->FormatBranch( $this->Payload->ref )
		);
	}
	
	/**
	 * Formats an issue event
	 * See https://developer.github.com/v3/activity/events/types/#issuesevent
	 */
	private function FormatIssuesEvent( ) : string
	{
		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'unpinned'
		||  $this->Payload->action === 'milestoned'
		||  $this->Payload->action === 'demilestoned'
		||  $this->Payload->action === 'labeled'
		||  $this->Payload->action === 'unlabeled'
		||  $this->Payload->action === 'assigned'
		||  $this->Payload->action === 'unassigned' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}
		
		if( $this->Payload->action !== 'opened'
		&&  $this->Payload->action !== 'closed'
		&&  $this->Payload->action !== 'reopened'
		&&  $this->Payload->action !== 'deleted'
		&&  $this->Payload->action !== 'pinned'
		&&  $this->Payload->action !== 'locked'
		&&  $this->Payload->action !== 'unlocked'
		&&  $this->Payload->action !== 'transferred' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return sprintf( '[%s] %s %s issue %s: %s. %s',
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
	 * See https://developer.github.com/v3/activity/events/types/#pullrequestevent
	 */
	private function FormatPullRequestEvent( ) : string
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
		else if( $this->Payload->action === 'ready_for_review' )
		{
			$this->Payload->action = 'readied';
		}
		else if( $this->Payload->action === 'auto_merge_enabled' )
		{
			$this->Payload->action = 'enabled auto-merge';
		}
		else if( $this->Payload->action === 'converted_to_draft' )
		{
			$this->Payload->action = 'converted to draft';
		}
		
		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'synchronize'
		||  $this->Payload->action === 'labeled'
		||  $this->Payload->action === 'unlabeled'
		||  $this->Payload->action === 'assigned'
		||  $this->Payload->action === 'unassigned'
		||  $this->Payload->action === 'review_requested'
		||  $this->Payload->action === 'review_request_removed' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}
		
		if( $this->Payload->action !== 'opened'
		&&  $this->Payload->action !== 'reopened'
		&&  $this->Payload->action !== 'deleted'
		&&  $this->Payload->action !== 'merged'
		&&  $this->Payload->action !== 'locked'
		&&  $this->Payload->action !== 'unlocked'
		&&  $this->Payload->action !== 'readied'
		&&  $this->Payload->action !== 'enabled auto-merge'
		&&  $this->Payload->action !== 'converted to draft'
		&&  $this->Payload->action !== 'closed without merging' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return sprintf( '[%s] %s %s %spull request %s%s: %s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->Payload->pull_request->draft ? 'draft ' : '',
						$this->FormatNumber( '#' . $this->Payload->pull_request->number ),
						$this->Payload->action === 'merged' ?
							( ' from ' . $this->FormatName( $this->Payload->pull_request->user->login ) . ' to ' . $this->FormatBranch( $this->Payload->pull_request->base->ref ) ) :
							'',
						$this->Payload->pull_request->title,
						$this->FormatURL( $this->Payload->pull_request->html_url )
		);
	}
	
	/**
	 * Formats a milestone event
	 * See https://developer.github.com/v3/activity/events/types/#milestoneevent
	 */
	private function FormatMilestoneEvent( ) : string
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}
		
		if( $this->Payload->action !== 'opened'
		&&  $this->Payload->action !== 'closed'
		&&  $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return sprintf( '[%s] %s %s milestone %s: %s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->FormatNumber( sprintf( '#%d', $this->Payload->milestone->number ) ),
						$this->Payload->milestone->title,
						$this->FormatURL( $this->Payload->milestone->html_url )
		);
	}
	
	/**
	 * Formats a package event
	 * See https://docs.github.com/en/free-pro-team@latest/developers/webhooks-and-events/webhook-events-and-payloads#package
	 */
	private function FormatPackageEvent( ) : string
	{
		if( $this->Payload->action !== 'published'
		&&  $this->Payload->action !== 'updated' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return sprintf(
			'[%s] %s %s %s package: %s %s. %s',
			$this->FormatRepoName( ),
			$this->FormatName( $this->Payload->sender->login ),
			$this->FormatAction( ),
			$this->Payload->package->package_type,
			$this->Payload->package->name,
			$this->FormatBranch( $this->Payload->package->package_version->version ),
			$this->FormatURL( $this->Payload->package->html_url )
		);
	}

	/**
	 * Formats a project event
	 * See https://developer.github.com/v3/activity/events/types/#projectevent
	 */
	private function FormatProjectEvent( ) : string
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'closed'
		&&  $this->Payload->action !== 'reopened'
		&&  $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return sprintf( '[%s] %s %s project: %s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->Payload->project->name,
						$this->FormatURL( $this->Payload->project->html_url )
		);
	}

	/**
	 * Formats a release event
	 * See https://developer.github.com/v3/activity/events/types/#releaseevent
	 */
	private function FormatReleaseEvent( ) : string
	{
		if( $this->Payload->action !== 'published'
		&&  $this->Payload->action !== 'unpublished' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return sprintf( '[%s] %s %s a %srelease %s: %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->Payload->release->prerelease ? 'pre-' : '',
						$this->FormatBranch( empty( $this->Payload->release->name ) ? $this->Payload->release->tag_name : $this->Payload->release->name ),
						$this->FormatURL( $this->Payload->release->html_url )
		);
	}
	
	/**
	 * Formats a commit comment event
	 * See https://developer.github.com/v3/activity/events/types/#commitcommentevent
	 */
	private function FormatCommitCommentEvent( ) : string
	{
		if( $this->Payload->action !== 'created' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return sprintf( '[%s] %s commented on commit %s: %s %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatHash( substr( $this->Payload->comment->commit_id, 0, 6 ) ),
						$this->ShortMessage( $this->Payload->comment->body ),
						$this->FormatURL( $this->Payload->comment->html_url )
		);
	}
	
	/**
	 * Formats a issue comment event
	 * See https://developer.github.com/v3/activity/events/types/#issuecommentevent
	 */
	private function FormatIssueCommentEvent( ) : string
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}
		
		if( $this->Payload->action === 'created' )
		{
			return sprintf(
				'[%s] %s commented on %s %s: %s %s',
				$this->FormatRepoName( ),
				$this->FormatName( $this->Payload->sender->login ),
				$this->FormatNumber( '#' . $this->Payload->issue->number ),
				$this->FormatHash( '(' . $this->Payload->issue->title . ')' ),
				$this->ShortMessage( $this->Payload->comment->body ),
				$this->FormatURL( $this->Payload->comment->html_url )
			);
		}

		if( $this->Payload->action === 'deleted' )
		{
			return sprintf(
				'[%s] %s deleted comment in issue %s from %s',
				$this->FormatRepoName( ),
				$this->FormatName( $this->Payload->sender->login ),
				$this->FormatNumber( '#' . $this->Payload->issue->number ),
				$this->FormatName( $this->Payload->comment->user->login ),
			);
		}

		throw new NotImplementedException( $this->EventType, $this->Payload->action );
	}
	
	/**
	 * Formats a pull request review event
	 * See https://developer.github.com/v3/activity/events/types/#pullrequestreviewevent
	 */
	private function FormatPullRequestReviewEvent( ) : string
	{
		if( $this->Payload->action !== 'submitted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		if( $this->Payload->review->state === 'commented' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->review->state );
		}
		
		if( $this->Payload->review->state === 'changes_requested' )
		{
			$this->Payload->review->state = 'requested changes';
		}
		
		return sprintf( '[%s] %s %s%s pull request %s: %s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( $this->Payload->review->state ),
						$this->Payload->review->state === 'requested changes' ? ' in' : '',
						$this->FormatNumber( '#' . $this->Payload->pull_request->number ),
						$this->Payload->pull_request->title,
						$this->FormatURL( $this->Payload->review->html_url )
		);
	}
	
	/**
	 * Formats a pull request review comment event
	 * See https://developer.github.com/v3/activity/events/types/#pullrequestreviewcommentevent
	 */
	private function FormatPullRequestReviewCommentEvent( ) : string
	{
		if( $this->Payload->action !== 'created' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return sprintf( '[%s] %s reviewed pull request %s at %s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatNumber( '#' . $this->Payload->pull_request->number ),
						$this->FormatHash( substr( $this->Payload->comment->commit_id, 0, 6 ) ),
						$this->FormatURL( $this->Payload->comment->html_url )
		);
	}
	
/**
	 * Formats a pull request review comment event
	 * See https://docs.github.com/en/developers/webhooks-and-events/webhook-events-and-payloads#discussion
	 */
	private function FormatDiscussionEvent( ) : string
	{
		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'labeled'
		||  $this->Payload->action === 'unlabeled'
		||  $this->Payload->action === 'answered'
		||  $this->Payload->action === 'unanswered' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action === 'category_changed' )
		{
			$this->Payload->action = 'changed category';
		}

		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'deleted'
		&&  $this->Payload->action !== 'pinned'
		&&  $this->Payload->action !== 'unpinned'
		&&  $this->Payload->action !== 'locked'
		&&  $this->Payload->action !== 'unlocked'
		&&  $this->Payload->action !== 'transferred'
		&&  $this->Payload->action !== 'changed category' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return sprintf(
			'[%s] %s %s discussion %s: %s. %s',
			$this->FormatRepoName( ),
			$this->FormatName( $this->Payload->sender->login ),
			$this->FormatAction( ),
			$this->FormatNumber( sprintf( '#%d', $this->Payload->discussion->number ) ),
			$this->Payload->discussion->title,
			$this->FormatURL( $this->Payload->discussion->html_url )
		);
	}

	/**
	 * Formats a pull request review comment event
	 * See https://docs.github.com/en/developers/webhooks-and-events/webhook-events-and-payloads#discussion_comment
	 */
	private function FormatDiscussionCommentEvent( ) : string
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action === 'created' )
		{
			return sprintf(
				'[%s] %s commented on discussion %s %s: %s %s',
				$this->FormatRepoName( ),
				$this->FormatName( $this->Payload->sender->login ),
				$this->FormatNumber( '#' . $this->Payload->discussion->number ),
				$this->FormatHash( '(' . $this->Payload->discussion->title . ')' ),
				$this->ShortMessage( $this->Payload->comment->body ),
				$this->FormatURL( $this->Payload->comment->html_url )
			);
		}

		if( $this->Payload->action === 'deleted' )
		{
			return sprintf(
				'[%s] %s deleted comment in discussion %s from %s',
				$this->FormatRepoName( ),
				$this->FormatName( $this->Payload->sender->login ),
				$this->FormatNumber( '#' . $this->Payload->discussion->number ),
				$this->FormatName( $this->Payload->comment->user->login ),
			);
		}

		throw new NotImplementedException( $this->EventType, $this->Payload->action );
	}

	/**
	 * Formats a repository vulnerability alert event
	 * See https://developer.github.com/v3/activity/events/types/#repositoryvulnerabilityalertevent
	 */
	private function FormatRepositoryVulnerabilityAlertEvent( ) : string
	{
		if( $this->Payload->action === 'create' )
		{
			return sprintf( '[%s] ⚠ New vulnerability for %s: %s %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->alert->affected_package_name ),
							$this->FormatNumber( $this->Payload->alert->external_identifier ),
							$this->FormatURL( $this->Payload->alert->external_reference )
			);
		}
		else if( $this->Payload->action === 'resolve' )
		{
			$this->Payload->action = 'resolved';
		}
		else if( $this->Payload->action === 'dismiss' )
		{
			$this->Payload->action = 'dismissed';
		}
		else
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return sprintf( '[%s] Vulnerability %s for %s: %s %s',
						$this->FormatRepoName( ),
						$this->FormatAction( ),
						$this->FormatName( $this->Payload->alert->affected_package_name ),
						$this->FormatNumber( $this->Payload->alert->external_identifier ),
						$this->FormatURL( $this->Payload->alert->external_reference )
		);
	}

	/**
	 * Formats a member event
	 * See https://developer.github.com/v3/activity/events/types/#memberevent
	 */
	private function FormatMemberEvent( ) : string
	{
		if( $this->Payload->action !== 'added' && $this->Payload->action !== 'removed' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
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
	private function FormatGollumEvent( ) : string
	{
		$Message = '';
		
		foreach( $this->Payload->pages as $Page )
		{
			if( !empty( $Message ) )
			{
				$Message .= "\n";
			}
			
			// Append compare url since github doesn't provide one
			if( $Page->action === 'edited' )
			{
				$Page->html_url .= '/_compare/' . $Page->sha;
			}
			
			$Message .= sprintf( "[%s] %s %s %s: %s%s",
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( $Page->action ),
						$Page->title,
						empty( $Page->summary ) ? '' : ( $Page->summary . ' ' ),
						$this->FormatURL( $Page->html_url )
			);
		}
		
		return $Message;
	}
	
	/**
	 * Formats a ping event
	 * See https://developer.github.com/webhooks/#ping-event
	 */
	private function FormatPingEvent( ) : string
	{
		return sprintf( '[%s] Hook %s worked! Zen: %s',
						$this->FormatRepoName( ),
						$this->FormatHash( (string)$this->Payload->hook->id ),
						$this->FormatName( $this->Payload->zen )
		);
	}
	
	/**
	 * Format a public event. Without a doubt: the best GitHub event
	 * See https://developer.github.com/v3/activity/events/types/#publicevent
	 */
	private function FormatPublicEvent( ) : string
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
	private function FormatRepositoryEvent( ) : string
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}
		
		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'deleted'
		&&  $this->Payload->action !== 'archived'
		&&  $this->Payload->action !== 'unarchived'
		&&  $this->Payload->action !== 'transferred'
		&&  $this->Payload->action !== 'renamed'
		&&  $this->Payload->action !== 'publicized'
		&&  $this->Payload->action !== 'privatized' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}
		
		return sprintf( '[%s] %s %s this repository. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->FormatURL( $this->Payload->repository->html_url )
		);
	}
}
