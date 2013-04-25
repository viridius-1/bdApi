<?php

class bdApi_ControllerApi_Thread extends bdApi_ControllerApi_Abstract
{
	public function actionGetIndex()
	{
		$threadId = $this->_input->filterSingle('thread_id', XenForo_Input::UINT);
		if (!empty($threadId))
		{
			return $this->responseReroute(__CLASS__, 'get-single');
		}

		$forumId = $this->_input->filterSingle('forum_id', XenForo_Input::UINT);
		if (empty($forumId))
		{
			return $this->responseError(new XenForo_Phrase('bdapi_slash_threads_requires_forum_id'), 400);
		}

		$ftpHelper = $this->getHelper('ForumThreadPost');
		$forum = $this->getHelper('ForumThreadPost')->assertForumValidAndViewable($forumId);

		$visitor = XenForo_Visitor::getInstance();

		$pageNavParams = array(
				'forum_id' => $forum['node_id'],
		);
		$page = $this->_input->filterSingle('page', XenForo_Input::UINT);
		$limit = XenForo_Application::get('options')->discussionsPerPage;

		$inputLimit = $this->_input->filterSingle('limit', XenForo_Input::UINT);
		if (!empty($inputLimit))
		{
			$limit = $inputLimit;
			$pageNavParams['limit'] = $inputLimit;
		}

		$conditions = array(
				'deleted' => false,
				'moderated' => false,
				'node_id' => $forum['node_id'],
		);
		$fetchOptions = array(
				'limit' => $limit,
				'page' => $page
		);

		$order = $this->_input->filterSingle('order', XenForo_Input::STRING, array('default' => 'natural'));
		switch ($order)
		{
			case 'thread_create_date':
				$fetchOptions['order'] = 'post_date';
				$fetchOptions['orderDirection'] = 'asc';
				$pageNavParams['order'] = $order;
				break;
			case 'thread_create_date_reverse':
				$fetchOptions['order'] = 'post_date';
				$fetchOptions['orderDirection'] = 'desc';
				$pageNavParams['order'] = $order;
				break;
			case 'thread_update_date':
				$fetchOptions['order'] = 'last_post_date';
				$fetchOptions['orderDirection'] = 'asc';
				$pageNavParams['order'] = $order;
				break;
			case 'thread_update_date_reverse':
				$fetchOptions['order'] = 'last_post_date';
				$fetchOptions['orderDirection'] = 'desc';
				$pageNavParams['order'] = $order;
				break;
		}

		$threads = $this->_getThreadModel()->getThreads(
				$conditions,
				$this->_getThreadModel()->getFetchOptionsToPrepareApiData($fetchOptions)
		);

		$total = $this->_getThreadModel()->countThreads($conditions);

		$firstPostIds = array();
		$firstPosts = array();
		if (!$this->_isFieldExcluded('first_post'))
		{
			foreach ($threads as $thread)
			{
				$firstPostIds[] = $thread['first_post_id'];
			}
			$firstPosts = $this->_getPostModel()->getPostsByIds(
					$firstPostIds,
					$this->_getPostModel()->getFetchOptionsToPrepareApiData()
			);

			if (!$this->_isFieldExcluded('first_post.attachments'))
			{
				$firstPosts = $this->_getPostModel()->getAndMergeAttachmentsIntoPosts($firstPosts);
			}
		}

		$data = array(
				'threads' => $this->_filterDataMany($this->_getThreadModel()->prepareApiDataForThreads($threads, $forum, $firstPosts)),
				'threads_total' => $total,
		);

		bdApi_Data_Helper_Core::addPageLinks($data, $limit, $total, $page, 'threads',
		array(), $pageNavParams);

		return $this->responseData('bdApi_ViewApi_Thread_List', $data);
	}

	public function actionGetSingle()
	{
		$threadId = $this->_input->filterSingle('thread_id', XenForo_Input::UINT);

		$ftpHelper = $this->getHelper('ForumThreadPost');
		list($thread, $forum) = $ftpHelper->assertThreadValidAndViewable(
				$threadId,
				$this->_getThreadModel()->getFetchOptionsToPrepareApiData(),
				$this->_getForumModel()->getFetchOptionsToPrepareApiData()
		);

		$firstPost = array();
		if (!$this->_isFieldExcluded('first_post'))
		{
			$firstPost = $this->_getPostModel()->getPostById(
					$thread['first_post_id'],
					$this->_getPostModel()->getFetchOptionsToPrepareApiData()
			);

			if (!$this->_isFieldExcluded('first_post.attachments'))
			{
				$firstPosts = array($firstPost['post_id'] => $firstPost);
				$firstPosts = $this->_getPostModel()->getAndMergeAttachmentsIntoPosts($firstPosts);
				$firstPost = reset($firstPosts);
			}
		}

		$data = array(
				'thread' => $this->_filterDataSingle($this->_getThreadModel()->prepareApiDataForThread($thread, $forum, $firstPost)),
		);

		return $this->responseData('bdApi_ViewApi_Thread_Single', $data);
	}

	public function actionPostIndex()
	{
		$forumId = $this->_input->filterSingle('forum_id', XenForo_Input::UINT);

		$ftpHelper = $this->getHelper('ForumThreadPost');
		$forum = $this->getHelper('ForumThreadPost')->assertForumValidAndViewable($forumId);

		$visitor = XenForo_Visitor::getInstance();

		if (!$this->_getForumModel()->canPostThreadInForum($forum, $errorPhraseKey))
		{
			throw $this->getErrorOrNoPermissionResponseException($errorPhraseKey);
		}

		// the routine is very similar to XenForo_ControllerPublic_Forum::actionAddThread
		$input = $this->_input->filter(array(
				'thread_title' => XenForo_Input::STRING,
		));
		$input['post_body'] = $this->getHelper('Editor')->getMessageText('post_body', $this->_input);
		$input['post_body'] = XenForo_Helper_String::autoLinkBbCode($input['post_body']);

		// note: assumes that the message dw will pick up the username issues
		$writer = XenForo_DataWriter::create('XenForo_DataWriter_Discussion_Thread');
		$writer->bulkSet(array(
				'user_id'		=> $visitor['user_id'],
				'username'		=> $visitor['username'],
				'title'			=> $input['thread_title'],
				'node_id'		=> $forum['node_id'],
		));

		// discussion state changes instead of first message state
		$writer->set('discussion_state', $this->getModelFromCache('XenForo_Model_Post')->getPostInsertMessageState(array(), $forum));

		$postWriter = $writer->getFirstMessageDw();
		$postWriter->set('message', $input['post_body']);
		$postWriter->setExtraData(XenForo_DataWriter_DiscussionMessage::DATA_ATTACHMENT_HASH, $this->_getAttachmentTempHash(array(
				'node_id' => $forum['node_id'],
		)));
		$postWriter->setExtraData(XenForo_DataWriter_DiscussionMessage_Post::DATA_FORUM, $forum);

		$writer->setExtraData(XenForo_DataWriter_Discussion_Thread::DATA_FORUM, $forum);

		$writer->preSave();

		if (!$writer->hasErrors())
		{
			$this->assertNotFlooding('post');
		}

		$writer->save();

		$thread = $writer->getMergedData();

		$this->_getThreadWatchModel()->setVisitorThreadWatchStateFromInput($thread['thread_id'], array(
				// TODO
				'watch_thread_state' => 0,
				'watch_thread' => 0,
				'watch_thread_email' => 0,
		));

		$this->_getThreadModel()->markThreadRead($thread, $forum, XenForo_Application::$time);

		$this->_request->setParam('thread_id', $thread['thread_id']);
		return $this->responseReroute(__CLASS__, 'get-single');
	}

	public function actionDeleteIndex()
	{
		$threadId = $this->_input->filterSingle('thread_id', XenForo_Input::UINT);

		$ftpHelper = $this->getHelper('ForumThreadPost');
		list($thread, $forum) = $ftpHelper->assertThreadValidAndViewable($threadId);

		$deleteType = 'soft';
		$options = array(
				'reason' => '[bd] API',
		);

		if (!$this->_getThreadModel()->canDeleteThread($thread, $forum, $deleteType, $errorPhraseKey))
		{
			throw $this->getErrorOrNoPermissionResponseException($errorPhraseKey);
		}

		$this->_getThreadModel()->deleteThread($thread['thread_id'], $deleteType, $options);

		XenForo_Model_Log::logModeratorAction(
		'thread', $thread, 'delete_' . $deleteType, array('reason' => $options['reason'])
		);

		return $this->responseMessage(new XenForo_Phrase('bdapi_thread_x_has_been_deleted', array('thread_id' => $thread['thread_id'])));
	}

	public function actionPostAttachments()
	{
		$contentData = array(
				'node_id' => $this->_input->filterSingle('forum_id', XenForo_Input::UINT),
		);
		if (empty($contentData['node_id']))
		{
			return $this->responseError(new XenForo_Phrase(
					'bdapi_slash_threads_attachments_requires_forum_id'
			), 400);
		}
		$hash = $this->_getAttachmentTempHash($contentData);

		$attachmentHelper = $this->_getAttachmentHelper();
		$response = $attachmentHelper->doUpload('file', $hash, 'post', $contentData);

		if ($response instanceof XenForo_ControllerResponse_Abstract)
		{
			return $response;
		}

		$data = array(
				'attachment' => $this->_getPostModel()->prepareApiDataForAttachment($response, $hash),
		);

		return $this->responseData('bdApi_ViewApi_Thread_Attachments', $data);
	}

	public function actionGetNew()
	{
		$this->_assertRegistrationRequired();

		$visitor = XenForo_Visitor::getInstance();
		$threadModel = $this->_getThreadModel();

		$limit = $this->_input->filterSingle('limit', XenForo_Input::UINT);
		$maxResults = XenForo_Application::getOptions()->get('maximumSearchResults');
		if ($limit > 0)
		{
			$maxResults = min($maxResults, $limit);
		}

		$forumId = $this->_input->filterSingle('forum_id', XenForo_Input::UINT);
		if (empty($forumId))
		{
			$threadIds = $threadModel->getUnreadThreadIds($visitor->get('user_id'), array(
					'limit' => $maxResults,
			));
		}
		else
		{
			$ftpHelper = $this->getHelper('ForumThreadPost');
			$forum = $this->getHelper('ForumThreadPost')->assertForumValidAndViewable($forumId);
			$childNodeIds = array_keys($this->getModelFromCache('XenForo_Model_Node')->getChildNodesForNodeIds(array($forum['node_id'])));

			$threadIds = $threadModel->bdApi_getUnreadThreadIdsInForum($visitor->get('user_id'),
					array_merge(array($forum['node_id']), $childNodeIds),
					array(
							'limit' => $maxResults,
					)
			);
		}

		return $this->_getNewOrRecentResponse($threadIds);
	}

	public function actionGetRecent()
	{
		$visitor = XenForo_Visitor::getInstance();
		$threadModel = $this->_getThreadModel();

		$days = $this->_input->filterSingle('days', XenForo_Input::UINT);
		if ($days < 1)
		{
			$days = max(7, XenForo_Application::get('options')->readMarkingDataLifetime);
		}

		$limit = $this->_input->filterSingle('limit', XenForo_Input::UINT);
		$maxResults = XenForo_Application::getOptions()->get('maximumSearchResults');
		if ($limit > 0)
		{
			$maxResults = min($maxResults, $limit);
		}

		$conditions = array(
				'last_post_date' => array('>', XenForo_Application::$time - 86400 * $days),
				'deleted' => false,
				'moderated' => false,
				'find_new' => true,
		);

		$fetchOptions = array(
				'limit' => $maxResults,
				'order' => 'last_post_date',
				'orderDirection' => 'desc',
				'join' => XenForo_Model_Thread::FETCH_FORUM_OPTIONS,
		);

		$forumId = $this->_input->filterSingle('forum_id', XenForo_Input::UINT);
		if (!empty($forumId))
		{
			$ftpHelper = $this->getHelper('ForumThreadPost');
			$forum = $this->getHelper('ForumThreadPost')->assertForumValidAndViewable($forumId);
			$childNodeIds = array_keys($this->getModelFromCache('XenForo_Model_Node')->getChildNodesForNodeIds(array($forum['node_id'])));
			$conditions['node_id'] = array_merge(array($forum['node_id']), $childNodeIds);
		}

		$threadIds = array_keys($threadModel->getThreads($conditions, $fetchOptions));

		return $this->_getNewOrRecentResponse($threadIds);
	}

	protected function _getNewOrRecentResponse(array $threadIds)
	{
		$visitor = XenForo_Visitor::getInstance();
		$threadModel = $this->_getThreadModel();

		$results = array();
		$threads = $threadModel->getThreadsByIds(
				$threadIds,
				array(
						'join' =>
						XenForo_Model_Thread::FETCH_FORUM |
						XenForo_Model_Thread::FETCH_USER,
						'permissionCombinationId' => $visitor['permission_combination_id'],
				)
		);
		foreach ($threadIds AS $threadId)
		{
			if (!isset($threads[$threadId])) continue;
			$threadRef =& $threads[$threadId];

			$threadRef['permissions'] = XenForo_Permission::unserializePermissions($threadRef['node_permission_cache']);

			if ($threadModel->canViewThreadAndContainer($threadRef, $threadRef, $null, $threadRef['permissions']))
			{
				$results[] = array(
						'thread_id' => $threadId,
				);
			}
		}

		$data = array(
				'threads' => $results,
		);

		return $this->responseData('bdApi_ViewApi_Thread_NewOrRecent', $data);
	}

	/**
	 * @return XenForo_Model_Forum
	 */
	protected function _getForumModel()
	{
		return $this->getModelFromCache('XenForo_Model_Forum');
	}

	/**
	 * @return XenForo_Model_Thread
	 */
	protected function _getThreadModel()
	{
		return $this->getModelFromCache('XenForo_Model_Thread');
	}

	/**
	 * @return XenForo_Model_Post
	 */
	protected function _getPostModel()
	{
		return $this->getModelFromCache('XenForo_Model_Post');
	}

	/**
	 * @return XenForo_Model_ThreadWatch
	 */
	protected function _getThreadWatchModel()
	{
		return $this->getModelFromCache('XenForo_Model_ThreadWatch');
	}

	/**
	 * @return bdApi_ControllerHelper_Attachment
	 */
	protected function _getAttachmentHelper()
	{
		return $this->getHelper('bdApi_ControllerHelper_Attachment');
	}

	protected function _getAttachmentTempHash($contentData)
	{
		$prefix = '';

		if (!empty($contentData['node_id']))
		{
			$prefix = sprintf('node%d', $contentData['node_id']);
		}

		return md5(sprintf('%s%s',
				$prefix,
				XenForo_Application::getConfig()->get('globalSalt')
		));
	}
}