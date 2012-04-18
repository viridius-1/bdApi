<?php
/* Start auto-generated lines of code. Change made will be overwriten... */

class bdApi_ControllerAdmin_Token_Generated extends XenForo_ControllerAdmin_Abstract {

	public function actionIndex() {
		$tokenModel = $this->_getTokenModel();
		$tokens = $tokenModel->getTokens();
		
		$viewParams = array(
			'tokens' => $tokens
		);
		
		return $this->responseView('bdApi_ViewAdmin_Token_List', 'bdapi_token_list', $viewParams);
	}
	
	public function actionAdd() {
		$viewParams = array(
			'token' => array(),
			'allClient' => $this->getModelFromCache('bdApi_Model_Client')->getList(),
		);
		
		return $this->responseView('bdApi_ViewAdmin_Token_Edit', 'bdapi_token_edit', $viewParams);
	}
	
	public function actionEdit() {
		$id = $this->_input->filterSingle('token_id', XenForo_Input::UINT);
		$token = $this->_getTokenOrError($id);
		
		$viewParams = array(
			'token' => $token,
			'allClient' => $this->getModelFromCache('bdApi_Model_Client')->getList(),
		);
		
		return $this->responseView('bdApi_ViewAdmin_Token_Edit', 'bdapi_token_edit', $viewParams);
	}
	
	public function actionSave() {
		$this->_assertPostOnly();
		
		$id = $this->_input->filterSingle('token_id', XenForo_Input::UINT);

		$dwInput = $this->_input->filter(array('client_id' => 'uint', 'token_text' => 'string', 'expire_date' => 'uint', 'user_id' => 'uint', 'scope' => 'string'));
		
		$dw = $this->_getTokenDataWriter();
		if ($id) {
			$dw->setExistingData($id);
		}
		$dw->bulkSet($dwInput);
		

		
		$this->_prepareDwBeforeSaving($dw);
		
		$dw->save();

		return $this->responseRedirect(
			XenForo_ControllerResponse_Redirect::SUCCESS,
			XenForo_Link::buildAdminLink('api-tokens')
		);
	}
	
	public function actionDelete() {
		$id = $this->_input->filterSingle('token_id', XenForo_Input::UINT);
		$token = $this->_getTokenOrError($id);
		
		if ($this->isConfirmedPost()) {
			$dw = $this->_getTokenDataWriter();
			$dw->setExistingData($id);
			$dw->delete();

			return $this->responseRedirect(
				XenForo_ControllerResponse_Redirect::SUCCESS,
				XenForo_Link::buildAdminLink('api-tokens')
			);
		} else {
			$viewParams = array(
				'token' => $token
			);

			return $this->responseView('bdApi_ViewAdmin_Token_Delete', 'bdapi_token_delete', $viewParams);
		}
	}
	
	
	protected function _getTokenOrError($id, array $fetchOptions = array()) {
		$info = $this->_getTokenModel()->getTokenById($id, $fetchOptions);
		
		if (empty($info)) {
			throw $this->responseException($this->responseError(new XenForo_Phrase('bdapi_token_not_found'), 404));
		}
		
		return $info;
	}
	
	/**
	 * @return bdApi_Model_Token
	 */
	protected function _getTokenModel() {
		return $this->getModelFromCache('bdApi_Model_Token');
	}
	
	/**
	 * @return bdApi_DataWriter_Token
	 */
	protected function _getTokenDataWriter() {
		return XenForo_DataWriter::create('bdApi_DataWriter_Token');
	}
	
	protected function _prepareDwBeforeSaving(bdApi_DataWriter_Token $dw) {
		// this method should be overriden if datawriter requires special treatments
	}
}

/* End auto-generated lines of code. Feel free to make changes below */
class bdApi_ControllerAdmin_Token extends bdApi_ControllerAdmin_Token_Generated {
	// customized actions and whatelse should go here
}