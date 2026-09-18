<?php
declare(strict_types=1);

namespace GitHubWebHook;

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
			case 'release'       : return $this->FormatReleaseEvent( );
			case 'milestone'     : return $this->FormatMilestoneEvent( );
			case 'repository'    : return $this->FormatRepositoryEvent( );
			case 'pull_request'  : return $this->FormatPullRequestEvent( );
			case 'issue_comment' : return $this->FormatIssueCommentEvent( );
			case 'commit_comment': return $this->FormatCommitCommentEvent( );
			case 'pull_request_review': return $this->FormatPullRequestReviewEvent( );
			case 'pull_request_review_comment': return $this->FormatPullRequestReviewCommentEvent( );
			case 'repository_advisory': return $this->FormatRepositoryAdvisoryEvent( );
			case 'dependabot_alert': return $this->FormatDependabotAlertEvent( );
			case 'code_scanning_alert': return $this->FormatCodeScanningAlertEvent( );
			case 'secret_scanning_alert': return $this->FormatSecretScanningAlertEvent( );

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
			case 'reintroduced':
				return "\00307" . $Action . "\017";

			case 'closed'     :
			case 'closed as not planned':
			case 'merged'     :
				return "\00313" . $Action . "\017";

			case 'locked'     :
			case 'deleted'    :
			case 'dismissed'  :
			case 'auto-dismissed':
			case 'publicly leaked':
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
		$NewMessage = explode( "\n", $Message, 2 );
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
			else if( !str_ends_with( $NewMessage, '…' ) )
			{
				$NewMessage .= '…';
			}
		}

		return $NewMessage;
	}

	/**
	 * Formats a push event.
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
			$URL = "{$this->Payload->repository->html_url}/compare/{$this->Payload->before}..{$this->Payload->after}";
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
	 * Formats a deletion event.
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
	 * Formats an issue event.
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

		$Action = $this->Payload->action;

		if( $Action === 'closed' && ( $this->Payload->issue->state_reason ?? null ) === 'not_planned' )
		{
			$Action = 'closed as not planned';
		}

		return sprintf( '[%s] %s %s issue %s: %s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( $Action ),
						$this->FormatNumber( sprintf( '#%d', $this->Payload->issue->number ) ),
						$this->Payload->issue->title,
						$this->FormatURL( $this->Payload->issue->html_url )
		);
	}

	/**
	 * Formats a pull request event.
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
	 * Formats a milestone event.
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
	 * Formats a package event.
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
	 * Formats a release event.
	 */
	private function FormatReleaseEvent( ) : string
	{
		if( $this->Payload->action !== 'published'
		&&  $this->Payload->action !== 'unpublished' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Name = $this->FormatBranch( $this->Payload->release->tag_name );

		if( ( $this->Payload->release->name ?? '' ) !== '' && $this->Payload->release->name !== $this->Payload->release->tag_name )
		{
			$Name .= ' (' . $this->Payload->release->name . ')';
		}

		return sprintf( '[%s] %s %s a %s%srelease %s: %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->Payload->release->draft ? 'draft ' : '',
						$this->Payload->release->prerelease ? 'pre-' : '',
						$Name,
						$this->FormatURL( $this->Payload->release->html_url )
		);
	}

	/**
	 * Formats a commit comment event.
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
	 * Formats a issue comment event.
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
				'[%s] %s deleted comment in %s %s from %s',
				$this->FormatRepoName( ),
				$this->FormatName( $this->Payload->sender->login ),
				$this->FormatNumber( '#' . $this->Payload->issue->number ),
				$this->FormatHash( '(' . $this->Payload->issue->title . ')' ),
				$this->FormatName( $this->Payload->comment->user->login ),
			);
		}

		throw new NotImplementedException( $this->EventType, $this->Payload->action );
	}

	/**
	 * Formats a pull request review event.
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
	 * Formats a pull request review comment event.
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
	 * Formats a pull request review comment event.
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
	 * Formats a pull request review comment event.
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
	 * Formats a code scanning alert event.
	 */
	private function FormatCodeScanningAlertEvent( ) : string
	{
		if( $this->Payload->action === 'appeared_in_branch' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action === 'created' )
		{
			return sprintf( '[%s] ⚠ New code scanning alert %s: %s (%s) %s',
							$this->FormatRepoName( ),
							$this->FormatNumber( '#' . $this->Payload->alert->number ),
							$this->ShortMessage( $this->Payload->alert->rule->description ),
							$this->Payload->alert->rule->severity ?? 'none',
							$this->FormatURL( $this->Payload->alert->html_url )
			);
		}

		$Action = match( $this->Payload->action )
		{
			'fixed' => 'fixed',
			'closed_by_user' => 'dismissed',
			'reopened', 'reopened_by_user' => 'reopened',
			default => throw new NotImplementedException( $this->EventType, $this->Payload->action ),
		};

		return sprintf( '[%s] Code scanning alert %s %s: %s %s',
						$this->FormatRepoName( ),
						$this->FormatNumber( '#' . $this->Payload->alert->number ),
						$this->FormatAction( $Action ),
						$this->ShortMessage( $this->Payload->alert->rule->description ),
						$this->FormatURL( $this->Payload->alert->html_url )
		);
	}

	/**
	 * Formats a dependabot alert event.
	 */
	private function FormatDependabotAlertEvent( ) : string
	{
		$Action = match( $this->Payload->action )
		{
			'created' => 'created',
			'fixed' => 'fixed',
			'dismissed' => 'dismissed',
			'auto_dismissed' => 'auto-dismissed',
			'reopened', 'auto_reopened' => 'reopened',
			'reintroduced' => 'reintroduced',
			default => throw new NotImplementedException( $this->EventType, $this->Payload->action ),
		};

		$Advisory = $this->Payload->alert->security_advisory;

		if( $Action === 'created' )
		{
			return sprintf( '[%s] ⚠ New Dependabot alert %s for %s: %s (%s) %s',
							$this->FormatRepoName( ),
							$this->FormatNumber( '#' . $this->Payload->alert->number ),
							$this->FormatName( $this->Payload->alert->security_vulnerability->package->name ),
							$this->FormatNumber( $Advisory->cve_id ?? $Advisory->ghsa_id ),
							$Advisory->severity,
							$this->FormatURL( $this->Payload->alert->html_url )
			);
		}

		return sprintf( '[%s] Dependabot alert %s %s for %s: %s %s',
						$this->FormatRepoName( ),
						$this->FormatNumber( '#' . $this->Payload->alert->number ),
						$this->FormatAction( $Action ),
						$this->FormatName( $this->Payload->alert->security_vulnerability->package->name ),
						$this->FormatNumber( $Advisory->cve_id ?? $Advisory->ghsa_id ),
						$this->FormatURL( $this->Payload->alert->html_url )
		);
	}

	/**
	 * Formats a secret scanning alert event.
	 */
	private function FormatSecretScanningAlertEvent( ) : string
	{
		if( $this->Payload->action === 'assigned'
		||  $this->Payload->action === 'unassigned'
		||  $this->Payload->action === 'validated' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		$Action = match( $this->Payload->action )
		{
			'created' => 'created',
			'resolved' => 'resolved',
			'reopened' => 'reopened',
			'publicly_leaked' => 'publicly leaked',
			default => throw new NotImplementedException( $this->EventType, $this->Payload->action ),
		};

		$SecretType = $this->Payload->alert->secret_type_display_name ?? $this->Payload->alert->secret_type ?? 'unknown';

		if( $Action === 'created' )
		{
			if( isset( $this->Payload->alert->push_protection_bypassed_by ) )
			{
				$SecretType .= ' (push protection bypassed by ' . $this->FormatName( $this->Payload->alert->push_protection_bypassed_by->login ) . ')';
			}

			return sprintf( '[%s] ⚠ New secret scanning alert %s: %s %s',
							$this->FormatRepoName( ),
							$this->FormatNumber( '#' . $this->Payload->alert->number ),
							$SecretType,
							$this->FormatURL( $this->Payload->alert->html_url )
			);
		}

		if( $Action === 'resolved' && isset( $this->Payload->alert->resolution ) )
		{
			$SecretType .= ' (' . str_replace( '_', ' ', $this->Payload->alert->resolution ) . ')';
		}

		return sprintf( '[%s] %sSecret scanning alert %s %s: %s %s',
						$this->FormatRepoName( ),
						$Action === 'publicly leaked' ? '⚠ ' : '',
						$this->FormatNumber( '#' . $this->Payload->alert->number ),
						$this->FormatAction( $Action ),
						$SecretType,
						$this->FormatURL( $this->Payload->alert->html_url )
		);
	}

	/**
	 * Formats a repository advisory event.
	 */
	private function FormatRepositoryAdvisoryEvent( ) : string
	{
		if( $this->Payload->action === 'reported' )
		{
			// Reported advisories are private, so do not reveal what they are about
			return sprintf( '[%s] ⚠ %s privately reported a vulnerability: %s %s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatNumber( $this->Payload->repository_advisory->ghsa_id ),
							$this->FormatURL( $this->Payload->repository_advisory->html_url )
			);
		}

		if( $this->Payload->action !== 'published' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return sprintf( '[%s] %s %s a security advisory %s: %s (%s) %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->FormatNumber( $this->Payload->repository_advisory->cve_id ?? $this->Payload->repository_advisory->ghsa_id ),
						$this->ShortMessage( $this->Payload->repository_advisory->summary ),
						$this->Payload->repository_advisory->severity ?? 'unknown',
						$this->FormatURL( $this->Payload->repository_advisory->html_url )
		);
	}

	/**
	 * Formats a member event.
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
	 * Formats a gollum event (wiki).
	 */
	private function FormatGollumEvent( ) : string
	{
		$Message = '';

		foreach( $this->Payload->pages as $Page )
		{
			if( $Message !== '' )
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
						( $Page->summary ?? '' ) === '' ? '' : ( $Page->summary . ' ' ),
						$this->FormatURL( $Page->html_url )
			);
		}

		return $Message;
	}

	/**
	 * Formats a ping event.
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
	 * Format a public event. Without a doubt: the best GitHub event.
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
	 * Triggered when a repository is created..
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

		$From = '';

		if( $this->Payload->action === 'renamed' )
		{
			$From = ' from ' . $this->FormatName( $this->Payload->changes->repository->name->from );
		}
		else if( $this->Payload->action === 'transferred' )
		{
			$Owner = $this->Payload->changes->owner->from->user ?? $this->Payload->changes->owner->from->organization ?? null;

			if( $Owner !== null )
			{
				$From = ' from ' . $this->FormatName( $Owner->login );
			}
		}

		return sprintf( '[%s] %s %s this repository%s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$From,
						$this->FormatURL( $this->Payload->repository->html_url )
		);
	}
}
