<?php
	require_once(BASE_PATH . 'server/includes/core/class.indexsqlite.php');

	class AdvancedSearchListModule extends ListModule
	{
		/**
		 * Constructor
		 * @param		int		$id			unique id.
		 * @param		array		$data		list of all actions.
		 */
		function __construct($id, $data)
		{
			parent::__construct($id, $data);
			// TODO: create a new method in Properties class that will return only the properties we
			// need for search list (and perhaps for preview???)
			$this->properties = $GLOBALS["properties"]->getMailListProperties();
			$this->properties = array_merge($this->properties, $GLOBALS["properties"]->getAppointmentListProperties());
			$this->properties = array_merge($this->properties, $GLOBALS["properties"]->getContactListProperties());
			$this->properties = array_merge($this->properties, $GLOBALS["properties"]->getStickyNoteListProperties());
			$this->properties = array_merge($this->properties, $GLOBALS["properties"]->getTaskListProperties());
			$this->properties = array_merge($this->properties, array(
					'body' => PR_BODY,
					'html_body' => PR_HTML,
					'startdate' => "PT_SYSTIME:PSETID_Appointment:0x820d",
					'duedate' => "PT_SYSTIME:PSETID_Appointment:0x820e",
					'creation_time' => PR_CREATION_TIME,
					"task_duedate" => "PT_SYSTIME:PSETID_Task:0x8105"));
			$this->properties = getPropIdsFromStrings($GLOBALS["mapisession"]->getDefaultMessageStore(), $this->properties);

			
		}

		/**
		 * Executes all the actions in the $data variable.
		 * @return		boolean					true on success or false on failure.
		 */
		function execute()
		{
			foreach($this->data as $actionType => $action)
			{
				if(isset($actionType)) {
					try {
						$store = $this->getActionStore($action);
						$parententryid = $this->getActionParentEntryID($action);
						$entryid = $this->getActionEntryID($action);

						switch($actionType)
						{
							case "list":
							case "updatelist":
								$this->getDelegateFolderInfo($store);
								$this->messageList($store, $entryid, $action, $actionType);
								break;
							case "search":
								$this->search($store, $entryid, $action, $actionType);
								break;
							case "updatesearch":
								$this->updatesearch($store, $entryid, $action);
								break;
							case "stopsearch":
								$this->stopSearch($store, $entryid, $action);
								break;
							case "delete_searchfolder":
								$this->deleteSearchFolder($store, $entryid, $action);
								break;
						}
					} catch (MAPIException $e) {
						// This is a very nasty hack that makes sure that the WebApp doesn't show an error message when
						// search wants to throw an error. This is only done because a proper fix for this bug has not
						// been found yet. When WA-9161 is really solved, this should be removed again.
						if ( $actionType !== 'search' && $actionType !== 'updatesearch' && $actionType !== 'stopsearch' ){
							$this->processException($e, $actionType);
						} else {
							if ( DEBUG_LOADER === 'LOAD_SOURCE' ){
								// Log all info we can get about this error to the error log of the web server
								error_log("Error in search: \n" . var_export($e, true) . "\n\n" . var_export(debug_backtrace(), true));
							}
							// Send success feedback without data, as if nothing strange happened...
							$this->sendFeedback(true);
						}
					}
				}
			}
		}

		/**
		 * Function which retrieves a list of messages in a folder
		 * @param object $store MAPI Message Store Object
		 * @param string $entryid entryid of the folder
		 * @param array $action the action data, sent by the client
		 * @param string $actionType the action type, sent by the client
		 * @return boolean true on success or false on failure
		 */
		function messageList($store, $entryid, $action, $actionType)
		{
			$this->searchFolderList = false; // Set to indicate this is not the search result, but a normal folder content

			if($store && $entryid) {
				// Restriction
				$this->parseRestriction($action);

				// Sort
				$this->parseSortOrder($action, null, true);

				$limit = false;
				if(isset($action['restriction']['limit'])){
					$limit = $action['restriction']['limit'];
				}

				$isSearchFolder = isset($action['search_folder_entryid']);
				$entryid = $isSearchFolder ? hex2bin($action['search_folder_entryid']) : $entryid;

				// Get the table and merge the arrays
				$data = $GLOBALS["operations"]->getTable($store, $entryid, $this->properties, $this->sort, $this->start, $limit, $this->restriction);

				// If the request come from search folder then no need to send folder information
				if (!$isSearchFolder) {
					// Open the folder.
					$folder = mapi_msgstore_openentry($store, $entryid);
					$data["folder"] = array();

					// Obtain some statistics from the folder contents
					$contentcount = mapi_getprops($folder, array(PR_CONTENT_COUNT, PR_CONTENT_UNREAD));
					if (isset($contentcount[PR_CONTENT_COUNT])) {
						$data["folder"]["content_count"] = $contentcount[PR_CONTENT_COUNT];
					}

					if (isset($contentcount[PR_CONTENT_UNREAD])) {
						$data["folder"]["content_unread"] = $contentcount[PR_CONTENT_UNREAD];
					}
				}

				$data = $this->filterPrivateItems($data);

				// Allowing to hook in just before the data sent away to be sent to the client
				$GLOBALS['PluginManager']->triggerHook('server.module.listmodule.list.after', array(
					'moduleObject' =>& $this,
					'store' => $store,
					'entryid' => $entryid,
					'action' => $action,
					'data' =>& $data
				));

				// unset will remove the value but will not regenerate array keys, so we need to
				// do it here
				$data["item"] = array_values($data["item"]);
				$this->addActionData($actionType, $data);
				$GLOBALS["bus"]->addData($this->getResponseData());
			}
		}
		
		private static function parsePatterns($restriction, &$patterns) {
			if (empty($restriction)) {
				return;
			}
			$type = $restriction[0];
			if ($type == RES_CONTENT) {
				$subres = $restriction[1];
				switch ($subres[ULPROPTAG]) {
				case PR_SUBJECT:
					$patterns['subject'] = $subres[VALUE][$subres[ULPROPTAG]];
					break;
				case PR_BODY:
					$patterns['content'] = $subres[VALUE][$subres[ULPROPTAG]];
					$patterns['attachments'] = $subres[VALUE][$subres[ULPROPTAG]];
					break;
				case PR_SENDER_NAME:
					$patterns['sender'] = $subres[VALUE][$subres[ULPROPTAG]];
					break;
				case PR_SENT_REPRESENTING_NAME:
					$patterns['from'] = $subres[VALUE][$subres[ULPROPTAG]];
					break;
				case PR_DISPLAY_TO:
				case PR_DISPLAY_CC:
					$patterns['recipients'] = $subres[VALUE][$subres[ULPROPTAG]];
					break;
				case PR_MESSAGE_CLASS:
					if (empty($patterns['message_classes'])) {
						$patterns['message_classes'] = array();
					}
					$patterns['message_classes'][] = $subres[VALUE][$subres[ULPROPTAG]];
					break;
				case PR_DISPLAY_NAME:
					$patterns['others'] = $subres[VALUE][$subres[ULPROPTAG]];
					break;
				}
			} else if ($type == RES_AND || $type == RES_OR) {
				foreach ($restriction[1] as $subres) {
					AdvancedSearchListModule::parsePatterns($subres, $patterns);
				}
			} else if ($type == RES_BITMASK) {
				$subres = $restriction[1];
				if ($subres[ULPROPTAG] == PR_MESSAGE_FLAGS && $subres[ULTYPE] == BMR_EQZ) {
					if (MSGFLAG_READ & $subres[ULMASK]) {
						$patterns['unread'] = true;
					}
				}
			} else if ($type == RES_PROPERTY) {
				$subres = $restriction[1];
				if (($subres[ULPROPTAG] == PR_MESSAGE_DELIVERY_TIME ||
					$subres[ULPROPTAG] == PR_LAST_MODIFICATION_TIME)) {
					if ($subres[RELOP] == RELOP_LT ||
						$subres[RELOP] == RELOP_LE) {
						$patterns['date_end'] = $subres[VALUE][$subres[ULPROPTAG]];
					} else if ($subres[RELOP] == RELOP_GT ||
						$subres[RELOP] == RELOP_GE) {
						$patterns['date_start'] = $subres[VALUE][$subres[ULPROPTAG]];	
					}
				}
			} else if ($type == RES_SUBRESTRICTION) {
				$subres = $restriction[1];
				if (($subres[ULPROPTAG] == PR_MESSAGE_ATTACHMENTS)) {
					$patterns['has_attachments'] = true;
				}
			}
		}
		
		/**
		 *	Function will set search restrictions on search folder and start search process
		 *	and it will also parse visible columns and sorting data when sending results to client
		 *	@param		object		$store		MAPI Message Store Object
		 *	@param		hexString	$entryid	entryid of the folder
		 *	@param		object		$action		the action data, sent by the client
		 *  @param		string		$actionType	the action type, sent by the client
		 */
		function search($store, $entryid, $action, $actionType)
		{
			$useSearchFolder = isset($action["use_searchfolder"]) ? $action["use_searchfolder"] : false;
			if (!$useSearchFolder) {
				/**
				 * store doesn't support search folders so we can't use this
				 * method instead we will pass restriction to messageList and
				 * it will give us the restricted results
				 */
				return parent::messageList($store, $entryid, $action, "list");
			}
			$store_props = mapi_getprops($store, array(PR_MDB_PROVIDER, PR_DEFAULT_STORE));
			if ($store_props[PR_MDB_PROVIDER] == ZARAFA_STORE_PUBLIC_GUID ||
				empty($store_props[PR_DEFAULT_STORE]) || !$store_props[PR_DEFAULT_STORE]){
				// public store or share store do not support search folders
				return parent::messageList($store, $entryid, $action, "list");
			}
			if ($GLOBALS['entryid']->compareEntryIds(bin2hex($entryid), bin2hex(TodoList::getEntryId()))) {
				// todo list do not need to perform full text index search
				return parent::messageList($store, $entryid, $action, "list");
			}
			
			
			$this->searchFolderList = true; // Set to indicate this is not the normal folder, but a search folder
			$this->restriction = false;
			

			// Parse Restriction
			$this->parseRestriction($action);
			if($this->restriction == false) {
				// if error in creating restriction then send error to client
				$errorInfo = array();
				$errorInfo["error_message"] = _("Error in search, please try again") . ".";
				$errorInfo["original_error_message"] = "Error in parsing restrictions.";

				return $this->sendSearchErrorToClient($store, $entryid, $action, $errorInfo);
			}

			$isSetSearchFolderEntryId = isset($action['search_folder_entryid']);
			if($isSetSearchFolderEntryId) {
				$this->sessionData['searchFolderEntryId'] = $action['search_folder_entryid'];
			}

			if (isset($action['forceCreateSearchFolder']) && $action['forceCreateSearchFolder']) {
				$isSetSearchFolderEntryId = false;
			}

			// create or open search folder
			$searchFolder = $this->createSearchFolder($store, $isSetSearchFolderEntryId);
			if ($searchFolder === false) {
				// if error in creating search folder then send error to client
				$errorInfo = array();
				switch(mapi_last_hresult()) {
				case MAPI_E_NO_ACCESS:
					$errorInfo["error_message"] = _("Unable to perform search query, no permissions to create search folder.");
					break;
				case MAPI_E_NOT_FOUND:
					$errorInfo["error_message"] = _("Unable to perform search query, search folder not found.");
					break;
				default:
					$errorInfo["error_message"] = _("Unable to perform search query, store might not support searching.");
				}

				$errorInfo["original_error_message"] = _("Error in creating search folder.");

				return $this->sendSearchErrorToClient($store, $entryid, $action, $errorInfo);
			}

			$subfolder_flag = 0;
			$recursive = false;
			if (isset($action["subfolders"]) && $action["subfolders"] == "true") {
				$recursive = true;
				$subfolder_flag = RECURSIVE_SEARCH;
			}

			if(!is_array($entryid)) {
				$entryids = array($entryid);
			} else {
				$entryids = $entryid;
			}

			$searchFolderEntryId = $this->sessionData['searchFolderEntryId'];

			// check if searchcriteria has changed
			$restrictionCheck = md5(serialize($this->restriction) . $searchFolderEntryId . $subfolder_flag);

			// check if there is need to set searchcriteria again
			if(!isset($this->sessionData['searchCriteriaCheck']) || $restrictionCheck != $this->sessionData['searchCriteriaCheck']) {
				if (!empty($this->sessionData['searchOriginalEntryids'])) {
					// get entryids of original folders, and use it to set new search criteria
					$entryids = Array();
					for($index = 0; $index < count($this->sessionData['searchOriginalEntryids']); $index++) {
						$entryids[] = hex2bin($this->sessionData['searchOriginalEntryids'][$index]);
					}
				} else {
					// store entryids of original folders, so that can be used for re-setting the search criteria if needed
					$this->sessionData['searchOriginalEntryids'] = Array();
					for($index = 0, $len = count($entryids); $index < $len; $index++) {
						$this->sessionData['searchOriginalEntryids'][] = bin2hex($entryids[$index]);
					}
				}
				// we never start the seach folder because we will populate the search folder by ourselves
				mapi_folder_setsearchcriteria($searchFolder, $this->restriction, $entryids, $subfolder_flag|STOP_SEARCH);
				$this->sessionData['searchCriteriaCheck'] = $restrictionCheck;
			}

			if(isset($this->sessionData['searchCriteriaCheck']) || $restrictionCheck == $this->sessionData['searchCriteriaCheck']) {
				$folderEntryid = bin2hex($entryid);
				if($this->sessionData['searchOriginalEntryids'][0] !== $folderEntryid) {
					$this->sessionData['searchOriginalEntryids'][0] = $folderEntryid;
					// we never start the seach folder because we will populate the search folder by ourselves
					mapi_folder_setsearchcriteria($searchFolder, $this->restriction, array($entryid), $subfolder_flag|STOP_SEARCH);
				}
			}

			unset($action["restriction"]);

			// Sort
			$this->parseSortOrder($action);
			
			$search_patterns = array();
			AdvancedSearchListModule::parsePatterns($this->restriction, $search_patterns);
			if (isset($search_patterns['message_classes']) &&
				count($search_patterns['message_classes']) >= 7) {
				unset($search_patterns['message_classes']);
			}
			
			$indexDB = new IndexSqlite();
			if (!$indexDB->load()) {
				// if error in creating search folder then send error to client
				$errorInfo = array();
				$errorInfo["error_message"] = _("Unable to perform search query, store might not support searching.");
				$errorInfo["original_error_message"] = _("Error in creating search folder.");
				return $this->sendSearchErrorToClient($store, $entryid, $action, $errorInfo);
			}
			$search_result = $indexDB->search(hex2bin($searchFolderEntryId), $search_patterns['sender'], $search_patterns['from'],
				$search_patterns['recipients'], $search_patterns['subject'], $search_patterns['content'],
				$search_patterns['attachments'], $search_patterns['others'], $entryid, $recursive,
				$search_patterns['message_classes'], $search_patterns['date_start'], $search_patterns['date_end'],
				$search_patterns['unread'], $search_patterns['has_attachments']);
			if (false == $search_result) {
				// if error in creating search folder then send error to client
				$errorInfo = array();
				$errorInfo["error_message"] = _("Unable to perform search query, search folder not found.");
				$errorInfo["original_error_message"] = _("Error in creating search folder.");
				return $this->sendSearchErrorToClient($store, $entryid, $action, $errorInfo);
			}
			
			// Get the table and merge the arrays
			$table = $GLOBALS["operations"]->getTable($store, hex2bin($searchFolderEntryId), $this->properties, $this->sort, $this->start);
			// Create the data array, which will be send back to the client
			$data = array();
			$data = array_merge($data, $table);
			
			$this->getDelegateFolderInfo($store);
			$data = $this->filterPrivateItems($data);

			// remember which entryid's are send to the client
			$searchResults = array();
			foreach($table["item"] as $item) {
				// store entryid => last_modification_time mapping
				$searchResults[$item["entryid"]] = $item["props"]["last_modification_time"];
			}

			// store search results into session data
			if(!isset($this->sessionData['searchResults'])) {
				$this->sessionData['searchResults'] = array();
			}
			$this->sessionData['searchResults'][$searchFolderEntryId] = $searchResults;

			$result = mapi_folder_getsearchcriteria($searchFolder);

			$data["search_meta"] = array();
			$data["search_meta"]["searchfolder_entryid"] = $searchFolderEntryId;
			$data["search_meta"]["search_store_entryid"] = $action["store_entryid"];
			$data["search_meta"]["searchstate"] = $result["searchstate"];
			$data["search_meta"]["results"] = count($searchResults);

			// Reopen the search folder, because otherwise the suggestion property will
			// not have been updated
			$searchFolder = $this->createSearchFolder($store, true);
			$storeProps = mapi_getprops($searchFolder, array(PR_EC_SUGGESTION));
			if ( isset($storeProps[PR_EC_SUGGESTION]) ){
				$data["search_meta"]["suggestion"] = $storeProps[PR_EC_SUGGESTION];
			}

			$this->addActionData("search", $data);
			$GLOBALS["bus"]->addData($this->getResponseData());

			return true;

		}

	}
?>
