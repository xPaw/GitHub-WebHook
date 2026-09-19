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
			case 'package'       :
			case 'registry_package': return $this->FormatPackageEvent( );
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
			case 'project'       : return $this->FormatProjectEvent( );
			case 'projects_v2'   : return $this->FormatProjectV2Event( );
			case 'projects_v2_status_update': return $this->FormatProjectStatusUpdateEvent( );
			case 'branch_protection_configuration': return $this->FormatBranchProtectionConfigurationEvent( );
			case 'branch_protection_rule': return $this->FormatBranchProtectionRuleEvent( );
			case 'repository_ruleset': return $this->FormatRepositoryRulesetEvent( );
			case 'deploy_key'    : return $this->FormatDeployKeyEvent( );
			case 'meta'          : return $this->FormatMetaEvent( );
			case 'organization'  : return $this->FormatOrganizationEvent( );
			case 'org_block'     : return $this->FormatOrgBlockEvent( );
			case 'membership'    : return $this->FormatMembershipEvent( );
			case 'team'          : return $this->FormatTeamEvent( );
			case 'sponsorship'   : return $this->FormatSponsorshipEvent( );
			case 'workflow_run'  : return $this->FormatWorkflowRunEvent( );

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

	private function FormatAction( ?string $Action = null, ?string $Text = null ) : string
	{
		$Action ??= $this->Payload->action;
		$Text ??= $Action;

		switch( $Action )
		{
			// Something needs attention
			case 'alert created':
			case 'reopened'   :
			case 'reintroduced':
			case 'at risk'    :
				return "\00307" . $Text . "\017";

			// Something was set aside
			case 'dismissed'  :
			case 'auto-dismissed':
			case 'converted to draft':
			case 'archived'   :
			case 'inactive'   :
			case 'closed as not planned':
				return "\00314" . $Text . "\017";

			case 'closed'     :
			case 'merged'     :
			case 'complete'   :
				return "\00313" . $Text . "\017";

			case 'deleted'    :
			case 'removed'    :
			case 'blocked'    :
			case 'disabled'   :
			case 'off track'  :
			case 'review dismissed':
			case 'publicly leaked':
			case 'unpublished':
			case 'failed'     :
			case 'timed out'  :
			case 'failed to start':
			case 'force-pushed':
			case 'requested changes':
			case 'closed without merging':
				return "\00304" . $Text . "\017";

			default           :
				return "\00309" . $Text . "\017";
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
		$Message = self::Trim( $Message );
		$NewMessage = explode( "\n", $Message, 2 );
		$NewMessage = $NewMessage[ 0 ];

		if( mb_strlen( $NewMessage ) > $Limit )
		{
			$NewMessage = mb_substr( $NewMessage, 0, $Limit );
		}

		if( $NewMessage !== $Message )
		{
			// Tidy ellipsis
			if( mb_substr( $NewMessage, -3 ) === '...' )
			{
				$NewMessage = mb_substr( $NewMessage, 0, -3 ) . '…';
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
		$NewCommits = $this->FormatNumber( (string)$Num ) . ' new commit' . ( $Num === 1 ? '' : 's' );

		$Message = sprintf( '[%s] %s ',
			$this->FormatRepoName( ),
			$this->FormatName( $this->Payload->pusher->name )
		);

		if( $this->Payload->created )
		{
			if( str_starts_with( $this->Payload->ref, 'refs/tags/' ) )
			{
				$Message .= sprintf( 'tagged %s at %s',
					$this->FormatBranch( $this->RefName ),
					$this->BaseRefName !== null ?
						$this->FormatBranch( $this->BaseRefName ) :
						$this->FormatHash( $this->AfterSHA( ) )
				);
			}
			else
			{
				$Message .= sprintf( 'created %s', $this->FormatBranch( $this->RefName ) );

				if( $this->BaseRefName !== null )
				{
					$Message .= sprintf( ' from %s', $this->FormatBranch( $this->BaseRefName ) );
				}
				else if( $Num > 0 )
				{
					$Message .= sprintf( ' at %s', $this->FormatHash( $this->AfterSHA( ) ) );
				}

				if( $Num > 0 )
				{
					$Message .= sprintf( ' (+%s)', $NewCommits );
				}
			}
		}
		else if( $this->Payload->deleted )
		{
			throw new NotImplementedException( $this->EventType, 'deleted (use DeleteEvent if needed)' );
		}
		else if( $this->Payload->forced )
		{
			$Message .= sprintf( '%s %s from %s to %s',
				$this->FormatAction( 'force-pushed' ),
				$this->FormatBranch( $this->RefName ),
				$this->FormatHash( $this->BeforeSHA( ) ),
				$this->FormatHash( $this->AfterSHA( ) )
			);
		}
		else if( $Num === 0 && count( $this->Payload->commits ) > 0 )
		{
			if( $this->BaseRefName !== null )
			{
				$Message .= sprintf( 'merged %s into %s',
					$this->FormatBranch( $this->BaseRefName ),
					$this->FormatBranch( $this->RefName )
				);
			}
			else
			{
				$Message .= sprintf( 'fast-forwarded %s from %s to %s',
					$this->FormatBranch( $this->RefName ),
					$this->FormatHash( $this->BeforeSHA( ) ),
					$this->FormatHash( $this->AfterSHA( ) )
				);
			}
		}
		else
		{
			// Most pushes go to the default branch, so only other branches are worth naming
			$Message .= sprintf( 'pushed %s%s',
				$NewCommits,
				$this->IsDefaultBranch() ? '' : ' to ' . $this->FormatBranch( $this->RefName )
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
			$URL = $DistinctCommits[ 0 ]->url;
		}
		else
		{
			$URL = $this->Payload->compare;
		}

		if( $Num > 0 )
		{
			$CommitMessages = '';
			$Length = 0;

			// Only whole messages are listed, cutting the joined text could land inside of a color code
			while( --$Num >= 0 )
			{
				$CommitMessage = $this->ShortMessage( $DistinctCommits[ $Num ]->message, 50 );
				$Length += mb_strlen( $CommitMessage ) + 3;

				if( $Length > 200 && $CommitMessages !== '' )
				{
					$CommitMessages .= '…';
					break;
				}

				$CommitMessages .= ( $CommitMessages === '' ? '' : $this->FormatHash( ' | ' ) ) . $CommitMessage;
			}

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

		return sprintf( '[%s] %s %s %s %s',
			$this->FormatRepoName( ),
			$this->FormatName( $this->Payload->sender->login ),
			$this->FormatAction( 'deleted' ),
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
		||  $this->Payload->action === 'unassigned'
		||  $this->Payload->action === 'typed'
		||  $this->Payload->action === 'untyped'
		||  $this->Payload->action === 'field_added'
		||  $this->Payload->action === 'field_removed' )
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

		[ $Verb, $Suffix ] = self::ActionPhrase( $Action );

		return sprintf( '[%s] %s %s issue %s%s: %s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( $Action, $Verb ),
						$this->FormatNumber( '#' . $this->Payload->issue->number ),
						$Suffix,
						rtrim( $this->Payload->issue->title, '.' ),
						$this->FormatURL( $this->Payload->issue->html_url )
		);
	}

	/**
	 * Formats a pull request event.
	 */
	private function FormatPullRequestEvent( ) : string
	{
		$Action = $this->Payload->action;

		if( $Action === 'closed' )
		{
			if( $this->Payload->pull_request->merged === true )
			{
				$Action = 'merged';
			}
			else
			{
				$Action = 'closed without merging';
			}
		}
		else if( $Action === 'ready_for_review' )
		{
			$Action = 'readied';
		}
		else if( $Action === 'auto_merge_enabled' )
		{
			$Action = 'enabled auto-merge';
		}
		else if( $Action === 'converted_to_draft' )
		{
			$Action = 'converted to draft';
		}

		if( $Action === 'edited'
		||  $Action === 'synchronize'
		||  $Action === 'labeled'
		||  $Action === 'unlabeled'
		||  $Action === 'assigned'
		||  $Action === 'unassigned'
		||  $Action === 'review_requested'
		||  $Action === 'review_request_removed'
		||  $Action === 'milestoned'
		||  $Action === 'demilestoned'
		||  $Action === 'enqueued'
		||  $Action === 'dequeued'
		||  $Action === 'auto_merge_disabled'
		||  $Action === 'stacked' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $Action );
		}

		if( $Action !== 'opened'
		&&  $Action !== 'reopened'
		&&  $Action !== 'merged'
		&&  $Action !== 'locked'
		&&  $Action !== 'unlocked'
		&&  $Action !== 'readied'
		&&  $Action !== 'enabled auto-merge'
		&&  $Action !== 'converted to draft'
		&&  $Action !== 'closed without merging' )
		{
			throw new NotImplementedException( $this->EventType, $Action );
		}

		[ $Verb, $Suffix ] = self::ActionPhrase( $Action );

		return sprintf( '[%s] %s %s %spull request %s%s%s: %s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( $Action, $Verb ),
						$this->Payload->pull_request->draft && $Action !== 'converted to draft' ? 'draft ' : '',
						$this->FormatNumber( '#' . $this->Payload->pull_request->number ),
						$Suffix,
						$Action === 'merged' ?
							( ' from ' . $this->FormatName( $this->Payload->pull_request->user->login ?? 'ghost' ) . ' to ' . $this->FormatBranch( $this->Payload->pull_request->base->ref ) ) :
							'',
						rtrim( $this->Payload->pull_request->title, '.' ),
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

		// A new milestone is "created", GitHub calls reopening a closed one "opened"
		$Action = $this->Payload->action === 'opened' ? 'reopened' : $this->Payload->action;

		return sprintf( '[%s] %s %s milestone %s: %s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( $Action ),
						$this->FormatNumber( '#' . $this->Payload->milestone->number ),
						rtrim( $this->Payload->milestone->title, '.' ),
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

		// Both events have the same payload under a different name
		$Package = $this->Payload->registry_package ?? $this->Payload->package;

		return sprintf(
			'[%s] %s %s %s package: %s%s. %s',
			$this->FormatRepoName( ),
			$this->FormatName( $this->Payload->sender->login ),
			$this->FormatAction( ),
			strtolower( $Package->package_type ),
			$Package->name,
			( $Package->package_version->version ?? '' ) === '' ? '' : ' ' . $this->FormatBranch( $Package->package_version->version ),
			$this->FormatURL( $Package->html_url )
		);
	}

	/**
	 * Formats a release event.
	 */
	private function FormatReleaseEvent( ) : string
	{
		if( $this->Payload->action === 'created'
		||  $this->Payload->action === 'edited'
		||  $this->Payload->action === 'released'
		||  $this->Payload->action === 'prereleased' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'published'
		&&  $this->Payload->action !== 'unpublished'
		&&  $this->Payload->action !== 'deleted' )
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
		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'pinned'
		||  $this->Payload->action === 'unpinned' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action === 'created' )
		{
			return sprintf(
				'[%s] %s commented on %s %s %s: %s %s',
				$this->FormatRepoName( ),
				$this->FormatName( $this->Payload->sender->login ),
				isset( $this->Payload->issue->pull_request ) ? 'pull request' : 'issue',
				$this->FormatNumber( '#' . $this->Payload->issue->number ),
				$this->FormatHash( '(' . $this->Payload->issue->title . ')' ),
				$this->ShortMessage( $this->Payload->comment->body ),
				$this->FormatURL( $this->Payload->comment->html_url )
			);
		}

		if( $this->Payload->action === 'deleted' )
		{
			return sprintf(
				'[%s] %s deleted comment in %s %s %s from %s',
				$this->FormatRepoName( ),
				$this->FormatName( $this->Payload->sender->login ),
				isset( $this->Payload->issue->pull_request ) ? 'pull request' : 'issue',
				$this->FormatNumber( '#' . $this->Payload->issue->number ),
				$this->FormatHash( '(' . $this->Payload->issue->title . ')' ),
				$this->FormatName( $this->Payload->comment->user->login ?? 'ghost' ),
			);
		}

		throw new NotImplementedException( $this->EventType, $this->Payload->action );
	}

	/**
	 * Formats a pull request review event.
	 */
	private function FormatPullRequestReviewEvent( ) : string
	{
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'submitted'
		&&  $this->Payload->action !== 'dismissed' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$State = $this->Payload->review->state;

		if( $this->Payload->action === 'dismissed' )
		{
			$State = 'dismissed';
		}
		else if( $State === 'commented' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $State );
		}
		else if( $State === 'changes_requested' )
		{
			$State = 'requested changes';
		}

		return sprintf( '[%s] %s %s%s pull request %s: %s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( $State === 'dismissed' ? 'review dismissed' : $State, $State ),
						match( $State )
						{
							'requested changes' => ' in',
							'dismissed' => ' a review on',
							default => '',
						},
						$this->FormatNumber( '#' . $this->Payload->pull_request->number ),
						rtrim( $this->Payload->pull_request->title, '.' ),
						$this->FormatURL( $this->Payload->review->html_url )
		);
	}

	/**
	 * Formats a pull request review comment event.
	 */
	private function FormatPullRequestReviewCommentEvent( ) : string
	{
		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'deleted' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'created' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return sprintf( '[%s] %s commented on the code of pull request %s %s: %s %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatNumber( '#' . $this->Payload->pull_request->number ),
						$this->FormatHash( '(' . $this->Payload->pull_request->title . ')' ),
						$this->ShortMessage( $this->Payload->comment->body ),
						$this->FormatURL( $this->Payload->comment->html_url )
		);
	}

	/**
	 * Formats a discussion event.
	 */
	private function FormatDiscussionEvent( ) : string
	{
		$Action = $this->Payload->action;

		if( $Action === 'edited'
		||  $Action === 'labeled'
		||  $Action === 'unlabeled'
		||  $Action === 'unanswered' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $Action );
		}

		if( $Action === 'category_changed' )
		{
			$Action = 'changed category';
		}

		if( $Action !== 'created'
		&&  $Action !== 'deleted'
		&&  $Action !== 'pinned'
		&&  $Action !== 'unpinned'
		&&  $Action !== 'locked'
		&&  $Action !== 'unlocked'
		&&  $Action !== 'transferred'
		&&  $Action !== 'answered'
		&&  $Action !== 'closed'
		&&  $Action !== 'reopened'
		&&  $Action !== 'changed category' )
		{
			throw new NotImplementedException( $this->EventType, $Action );
		}

		[ $Verb, $Suffix ] = self::ActionPhrase( $Action );

		return sprintf(
			'[%s] %s %s discussion %s%s: %s. %s',
			$this->FormatRepoName( ),
			$this->FormatName( $this->Payload->sender->login ),
			$this->FormatAction( $Action, $Verb ),
			$this->FormatNumber( '#' . $this->Payload->discussion->number ),
			$Suffix,
			rtrim( $this->Payload->discussion->title, '.' ),
			$this->FormatURL( $Action === 'answered' ? ( $this->Payload->answer->html_url ?? $this->Payload->discussion->html_url ) : $this->Payload->discussion->html_url )
		);
	}

	/**
	 * Formats a discussion comment event.
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
				$this->FormatName( $this->Payload->comment->user->login ?? 'ghost' ),
			);
		}

		throw new NotImplementedException( $this->EventType, $this->Payload->action );
	}

	/**
	 * Formats a code scanning alert event.
	 */
	private function FormatCodeScanningAlertEvent( ) : string
	{
		if( $this->Payload->action === 'appeared_in_branch'
		||  $this->Payload->action === 'updated_assignment' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action === 'created' )
		{
			return sprintf( '[%s] ⚠ Code scanning alert %s %s: %s (%s) %s',
							$this->FormatRepoName( ),
							$this->FormatNumber( '#' . $this->Payload->alert->number ),
							$this->FormatAction( 'alert created', 'created' ),
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

		return sprintf( '[%s] %sCode scanning alert %s %s: %s %s',
						$this->FormatRepoName( ),
						$Action === 'reopened' ? '⚠ ' : '',
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
		if( $this->Payload->action === 'assignees_changed' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

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
			return sprintf( '[%s] ⚠ Dependabot alert %s %s for %s: %s (%s, %s) %s',
							$this->FormatRepoName( ),
							$this->FormatNumber( '#' . $this->Payload->alert->number ),
							$this->FormatAction( 'alert created', 'created' ),
							$this->FormatName( $this->Payload->alert->security_vulnerability->package->name ),
							$this->ShortMessage( $Advisory->summary ),
							$this->FormatNumber( $Advisory->cve_id ?? $Advisory->ghsa_id ),
							$Advisory->severity,
							$this->FormatURL( $this->Payload->alert->html_url )
			);
		}

		return sprintf( '[%s] %sDependabot alert %s %s for %s: %s (%s) %s',
						$this->FormatRepoName( ),
						$Action === 'reopened' || $Action === 'reintroduced' ? '⚠ ' : '',
						$this->FormatNumber( '#' . $this->Payload->alert->number ),
						$this->FormatAction( $Action ),
						$this->FormatName( $this->Payload->alert->security_vulnerability->package->name ),
						$this->ShortMessage( $Advisory->summary ),
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
		||  $this->Payload->action === 'validated'
		||  $this->Payload->action === 'metadata_created'
		||  $this->Payload->action === 'metadata_removed' )
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
				$SecretType .= ' (push protection bypassed by ' . $this->FormatName( $this->Payload->alert->push_protection_bypassed_by->login ?? 'ghost' ) . ')';
			}

			return sprintf( '[%s] ⚠ Secret scanning alert %s %s: %s %s',
							$this->FormatRepoName( ),
							$this->FormatNumber( '#' . $this->Payload->alert->number ),
							$this->FormatAction( 'alert created', 'created' ),
							$SecretType,
							$this->FormatURL( $this->Payload->alert->html_url )
			);
		}

		if( $Action === 'resolved' && ( $this->Payload->alert->resolution ?? '' ) !== '' )
		{
			$SecretType .= ' (' . str_replace( '_', ' ', $this->Payload->alert->resolution ) . ')';
		}

		return sprintf( '[%s] %sSecret scanning alert %s %s: %s %s',
						$this->FormatRepoName( ),
						$Action === 'publicly leaked' || $Action === 'reopened' ? '⚠ ' : '',
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

		return sprintf( '[%s] ⚠ %s %s a security advisory %s: %s (%s) %s',
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
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'added' && $this->Payload->action !== 'removed' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return sprintf( '[%s] %s %s %s as a collaborator',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->FormatName( $this->Payload->member->login ?? 'ghost' )
		);
	}

	/**
	 * Formats a gollum event (wiki).
	 */
	private function FormatGollumEvent( ) : string
	{
		$Message = '';

		foreach( array_slice( $this->Payload->pages, 0, self::MAX_WIKI_PAGES ) as $Page )
		{
			if( $Message !== '' )
			{
				$Message .= "\n";
			}

			$URL = $Page->html_url;

			// Append compare url since github doesn't provide one
			if( $Page->action === 'edited' )
			{
				$URL .= '/_compare/' . $Page->sha;
			}

			$Message .= sprintf( "[%s] %s %s %s: %s%s",
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( $Page->action ),
						$Page->title,
						( $Page->summary ?? '' ) === '' ? '' : ( $this->ShortMessage( $Page->summary ) . ' ' ),
						$this->FormatURL( $URL )
			);
		}

		$Remaining = count( $this->Payload->pages ) - self::MAX_WIKI_PAGES;

		if( $Remaining > 0 )
		{
			$Message .= sprintf( "\n[%s] %s updated %s more page%s %s",
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatNumber( (string)$Remaining ),
						$Remaining === 1 ? '' : 's',
						$this->FormatURL( $this->Payload->repository->html_url . '/wiki' )
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
						$this->FormatHash( (string)$this->Payload->hook_id ),
						$this->FormatName( $this->Payload->zen ?? '' )
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
	 * Formats a repository event.
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

	/**
	 * Formats a project (classic) event.
	 */
	private function FormatProjectEvent( ) : string
	{
		return $this->FormatProject(
			$this->Payload->project->number,
			$this->Payload->project->name,
			$this->Payload->project->html_url
		);
	}

	/**
	 * Formats a project event.
	 */
	private function FormatProjectV2Event( ) : string
	{
		return $this->FormatProject(
			$this->Payload->projects_v2->number,
			$this->Payload->projects_v2->title,
			$this->ProjectV2URL( )
		);
	}

	/**
	 * Formats a project of either kind, which only differ in where their fields are.
	 */
	private function FormatProject( int $Number, string $Name, string $URL ) : string
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

		return sprintf( '[%s] %s %s project %s: %s. %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->FormatNumber( '#' . $Number ),
						rtrim( $Name, '.' ),
						$this->FormatURL( $URL )
		);
	}

	/**
	 * Formats a project status update event.
	 */
	private function FormatProjectStatusUpdateEvent( ) : string
	{
		if( $this->Payload->action === 'edited'
		||  $this->Payload->action === 'deleted' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'created' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Status = self::ProjectStatus( $this->Payload->projects_v2_status_update->status ?? null );
		$Body = self::Trim( (string)( $this->Payload->projects_v2_status_update->body ?? '' ) );

		// The payload only has the node id of the project, so there is nothing to link but the list of them
		return sprintf( '[%s] %s posted a project status update%s%s %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$Status === null ? '' : ' (' . $this->FormatAction( $Status ) . ')',
						$Body === '' ? '' : ': ' . $this->ShortMessage( $Body ),
						$this->FormatURL( 'https://github.com/orgs/' . $this->Payload->organization->login . '/projects' )
		);
	}

	/**
	 * Formats a branch protection configuration event.
	 */
	private function FormatBranchProtectionConfigurationEvent( ) : string
	{
		if( $this->Payload->action !== 'enabled'
		&&  $this->Payload->action !== 'disabled' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return sprintf( '[%s] %s %s branch protection for all branches %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->FormatURL( $this->Payload->repository->html_url . '/settings/branches' )
		);
	}

	/**
	 * Formats a branch protection rule event.
	 */
	private function FormatBranchProtectionRuleEvent( ) : string
	{
		// An edit changes a dozen settings at a time, which is too much to put in a line
		if( $this->Payload->action === 'edited' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return sprintf( '[%s] %s %s branch protection rule %s %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->FormatBranch( $this->Payload->rule->name ),
						$this->FormatURL( $this->Payload->repository->html_url . '/settings/branches' )
		);
	}

	/**
	 * Formats a repository ruleset event.
	 */
	private function FormatRepositoryRulesetEvent( ) : string
	{
		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'edited'
		&&  $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Ruleset = $this->Payload->repository_ruleset;
		$URL = $Ruleset->_links->html->href ?? null;

		return sprintf( '[%s] %s %s ruleset: %s (%s)%s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$Ruleset->name,
						$Ruleset->enforcement,
						$URL === null ? '' : ' ' . $this->FormatURL( $URL )
		);
	}

	/**
	 * Formats a deploy key event.
	 */
	private function FormatDeployKeyEvent( ) : string
	{
		if( $this->Payload->action !== 'created'
		&&  $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		// A key that can write to the repository is worth telling apart from one that can not
		return sprintf( '[%s] %s %s deploy key: %s (%s) %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->Payload->key->title,
						$this->Payload->key->read_only ? 'read-only' : 'read-write',
						$this->FormatURL( $this->Payload->repository->html_url . '/settings/keys' )
		);
	}

	/**
	 * Formats a meta event, which says that this very webhook was deleted.
	 */
	private function FormatMetaEvent( ) : string
	{
		if( $this->Payload->action !== 'deleted' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return sprintf( '[%s] %s %s hook %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->FormatHash( (string)$this->Payload->hook_id )
		);
	}

	/**
	 * Formats an organization event.
	 */
	private function FormatOrganizationEvent( ) : string
	{
		$Action = match( $this->Payload->action )
		{
			'deleted' => 'deleted',
			'renamed' => 'renamed',
			'member_added' => 'added',
			'member_removed' => 'removed',
			'member_invited' => 'invited',
			default => throw new NotImplementedException( $this->EventType, $this->Payload->action ),
		};

		if( $Action === 'deleted' || $Action === 'renamed' )
		{
			$From = $this->Payload->changes->login->from ?? null;

			return sprintf( '[%s] %s %s this organization%s',
							$this->FormatRepoName( ),
							$this->FormatName( $this->Payload->sender->login ),
							$this->FormatAction( $Action ),
							$From === null ? '' : ' from ' . $this->FormatName( $From )
			);
		}

		$Member = $this->OrganizationMember( );
		$Role = $this->OrganizationRole( );

		return sprintf( '[%s] %s %s %s%s %s the organization',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( $Action ),
						$Member === null ? 'someone by email' : $this->FormatName( $Member ),
						$Role === null ? '' : ' (' . $Role . ')',
						$Action === 'removed' ? 'from' : 'to'
		);
	}

	/**
	 * Formats an organization block event.
	 */
	private function FormatOrgBlockEvent( ) : string
	{
		if( $this->Payload->action !== 'blocked'
		&&  $this->Payload->action !== 'unblocked' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return sprintf( '[%s] %s %s user %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->FormatName( $this->Payload->blocked_user->login ?? 'ghost' )
		);
	}

	/**
	 * Formats a sponsorship event, which only says that there is a new sponsor.
	 * What they pay is between them and the sponsored account.
	 */
	private function FormatSponsorshipEvent( ) : string
	{
		if( $this->Payload->action === 'cancelled'
		||  $this->Payload->action === 'edited'
		||  $this->Payload->action === 'tier_changed'
		||  $this->Payload->action === 'pending_cancellation'
		||  $this->Payload->action === 'pending_tier_change' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'created' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Sponsor = $this->Payload->sponsorship->sponsor->login ?? null;
		$Sponsored = $this->Payload->sponsorship->sponsorable->login ?? 'ghost';
		$URL = $this->FormatURL( 'https://github.com/sponsors/' . $Sponsored );

		// A private sponsor asked not to be named
		if( ( $this->Payload->sponsorship->privacy_level ?? null ) !== 'public' || $Sponsor === null )
		{
			return sprintf( '[%s] %s got a new private sponsor %s',
							$this->FormatRepoName( ),
							$this->FormatName( $Sponsored ),
							$URL
			);
		}

		return sprintf( '[%s] %s is now sponsoring %s %s',
						$this->FormatRepoName( ),
						$this->FormatName( $Sponsor ),
						$this->FormatName( $Sponsored ),
						$URL
		);
	}

	/**
	 * Formats a workflow run event. Only a run that broke the default branch is worth telling,
	 * the rest would be noise.
	 */
	private function FormatWorkflowRunEvent( ) : string
	{
		if( $this->Payload->action === 'requested'
		||  $this->Payload->action === 'in_progress' )
		{
			throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
		}

		if( $this->Payload->action !== 'completed' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		$Run = $this->Payload->workflow_run;

		$Outcome = match( $Run->conclusion ?? null )
		{
			'failure' => 'failed',
			'timed_out' => 'timed out',
			'startup_failure' => 'failed to start',
			default => throw new IgnoredEventException( $this->EventType . ' - ' . ( $Run->conclusion ?? 'null' ) ),
		};

		if( ( $Run->head_branch ?? null ) !== $this->Payload->repository->default_branch )
		{
			throw new IgnoredEventException( $this->EventType . ' - not the default branch' );
		}

		$Name = $Run->name ?? '';

		if( $Name === '' )
		{
			$Name = $this->Payload->workflow->name ?? 'unknown';
		}

		return sprintf( '[%s] Workflow %s %s on %s: %s %s',
						$this->FormatRepoName( ),
						$this->FormatName( $Name ),
						$this->FormatAction( $Outcome ),
						$this->FormatBranch( $Run->head_branch ),
						$this->ShortMessage( $Run->head_commit->message ?? '' ),
						$this->FormatURL( $Run->html_url )
		);
	}

	/**
	 * Formats a membership event.
	 */
	private function FormatMembershipEvent( ) : string
	{
		if( $this->Payload->action !== 'added'
		&&  $this->Payload->action !== 'removed' )
		{
			throw new NotImplementedException( $this->EventType, $this->Payload->action );
		}

		return sprintf( '[%s] %s %s %s %s team %s %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( ),
						$this->FormatName( $this->Payload->member->login ?? 'ghost' ),
						$this->Payload->action === 'added' ? 'to' : 'from',
						$this->FormatName( $this->Payload->team->name ),
						$this->FormatURL( $this->Payload->team->html_url )
		);
	}

	/**
	 * Formats a team event.
	 */
	private function FormatTeamEvent( ) : string
	{
		[ $Action, $Where ] = match( $this->Payload->action )
		{
			'created' => [ 'created', '' ],
			'deleted' => [ 'deleted', '' ],
			'edited' => [ 'edited', '' ],
			'added_to_repository' => [ 'added', ' to this repository' ],
			'removed_from_repository' => [ 'removed', ' from this repository' ],
			default => throw new NotImplementedException( $this->EventType, $this->Payload->action ),
		};

		$From = null;

		// An edit is worth telling when it renames a team or changes who can see it
		if( $Action === 'edited' )
		{
			$From = $this->Payload->changes->name->from ?? null;

			if( $From !== null )
			{
				$Action = 'renamed';
			}
			else if( isset( $this->Payload->changes->privacy ) )
			{
				return sprintf( '[%s] %s %s the privacy of team %s to %s %s',
								$this->FormatRepoName( ),
								$this->FormatName( $this->Payload->sender->login ),
								$this->FormatAction( 'changed' ),
								$this->FormatName( $this->Payload->team->name ),
								$this->Payload->team->privacy ?? 'unknown',
								$this->FormatURL( $this->Payload->team->html_url )
				);
			}
			else
			{
				throw new IgnoredEventException( $this->EventType . ' - ' . $this->Payload->action );
			}
		}

		return sprintf( '[%s] %s %s team %s%s%s %s',
						$this->FormatRepoName( ),
						$this->FormatName( $this->Payload->sender->login ),
						$this->FormatAction( $Action ),
						$this->FormatName( $this->Payload->team->name ),
						$Where,
						$From === null ? '' : ' from ' . $this->FormatName( $From ),
						$this->FormatURL( $this->Payload->team->html_url )
		);
	}

	/**
	 * Projects have no url of their own in the payload, they live under the organization that owns them.
	 */
	private function ProjectV2URL( ) : string
	{
		return sprintf( 'https://github.com/orgs/%s/projects/%d',
						$this->Payload->organization->login,
						$this->Payload->projects_v2->number
		);
	}

	/**
	 * The user an organization member event is about. An invitation by email has no account yet,
	 * and the address is not something to announce.
	 */
	private function OrganizationMember( ) : ?string
	{
		if( $this->Payload->action === 'member_invited' )
		{
			return $this->Payload->user->login ?? $this->Payload->invitation->login ?? null;
		}

		return $this->Payload->membership->user->login ?? 'ghost';
	}

	/**
	 * The role of the member an organization event is about. An invitation calls a plain member
	 * a direct member, and reinstating someone gives back the role they had, which is not named.
	 */
	private function OrganizationRole( ) : ?string
	{
		$Role = $this->Payload->membership->role ?? $this->Payload->invitation->role ?? null;

		if( !is_string( $Role ) || $Role === 'reinstate' )
		{
			return null;
		}

		return $Role === 'direct_member' ? 'member' : str_replace( '_', ' ', $Role );
	}

	/**
	 * GitHub sends the status of a project as an enum such as `OFF_TRACK`, and it can be unset.
	 */
	private static function ProjectStatus( mixed $Status ) : ?string
	{
		return is_string( $Status ) ? strtolower( str_replace( '_', ' ', $Status ) ) : null;
	}
}
