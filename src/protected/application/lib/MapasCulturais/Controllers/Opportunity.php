<?php
namespace MapasCulturais\Controllers;

use MapasCulturais\i;
use MapasCulturais\App;
use MapasCulturais\Traits;
use MapasCulturais\Entities;
use MapasCulturais\ApiQuery;

/**
 * Opportunity Controller
 *
 * By default this controller is registered with the id 'opportunity'.
 *
 *  @property-read \MapasCulturais\Entities\Opportunity $requestedEntity The Requested Entity
 */
class Opportunity extends EntityController {
    use Traits\ControllerUploads,
        Traits\ControllerTypes,
        Traits\ControllerMetaLists,
        Traits\ControllerAgentRelation,
        Traits\ControllerSealRelation,
        Traits\ControllerSoftDelete,
        Traits\ControllerChangeOwner,
        Traits\ControllerDraft,
        Traits\ControllerArchive,
        Traits\ControllerAPI,
        Traits\ControllerAPINested;

    function GET_create() {
        // @TODO: definir entitidade relacionada

        parent::GET_create();
    }

    function ALL_sendEvaluations(){
        $this->requireAuthentication();

        $app = App::i();

        $opportunity = $this->requestedEntity;

        if(!$opportunity)
            $app->pass();

        $opportunity->sendUserEvaluations();

        if($app->request->isAjax()){
            $this->json($opportunity);
        }else{
            $app->redirect($app->request->getReferer());
        }
    }

    function ALL_publishRegistrations(){
        $this->requireAuthentication();

        $app = App::i();

        $opportunity = $this->requestedEntity;

        if(!$opportunity)
            $app->pass();

        $opportunity->publishRegistrations();

        if($app->request->isAjax()){
            $this->json($opportunity);
        }else{
            $app->redirect($app->request->getReferer());
        }
    }


    function GET_report(){
        $this->requireAuthentication();
        $app = App::i();

        $entity = $this->requestedEntity;

        if(!$entity){
            $app->pass();
        }

        $entity->checkPermission('@control');

        $app->controller('Registration')->registerRegistrationMetadata($entity);

        $filename = sprintf(\MapasCulturais\i::__("oportunidade-%s--inscricoes"), $entity->id);

        $this->reportOutput('report', ['entity' => $entity], $filename);

    }


    function GET_reportEvaluations(){
        $this->requireAuthentication();
        $app = App::i();

        $entity = $this->requestedEntity;

        if(!$entity)
            $app->pass();


        $entity->checkPermission('canUserViewEvaluations');

        $app->controller('Registration')->registerRegistrationMetadata($entity);

        $evaluations = $app->repo('RegistrationEvaluation')->findByOpportunity($entity);

        $filename = sprintf(\MapasCulturais\i::__("oportunidade-%s--avaliacoes"), $entity->id);

        $this->reportOutput('report-evaluations', ['entity' => $entity, 'evaluations' => $evaluations], $filename);

    }

    protected function reportOutput($view, $view_params, $filename){
        $app = App::i();

        if(!isset($this->urlData['output']) || $this->urlData['output'] == 'xls'){
            $response = $app->response();
            $response['Content-Encoding'] = 'UTF-8';
            $response['Content-Type'] = 'application/force-download';
            $response['Content-Disposition'] ='attachment; filename=' . $filename . '.xls';
            $response['Pragma'] ='no-cache';

            $app->contentType('application/vnd.ms-excel; charset=UTF-8');
        }

        ob_start();
        $this->partial($view, $view_params);
        $output = ob_get_clean();
        echo mb_convert_encoding($output,"HTML-ENTITIES","UTF-8");
    }


    function API_findByUserApprovedRegistration(){
        $this->requireAuthentication();
        $app = App::i();

        $dql = "SELECT r
                FROM \MapasCulturais\Entities\Registration r
                JOIN r.opportunity p
                JOIN r.owner a
                WHERE a.user = :user
                AND r.status > 0";
        $query = $app->em->createQuery($dql)->setParameters(['user' => $app->user]);

        $registrations = $query->getResult();


        $opportunities = array_map(function($r){
            return $r->opportunity;
        }, $registrations);

        $this->apiResponse($opportunities);
    }
    
    /**
    * @return \MapasCulturais\Entities\Opportunity
    */
    protected function _getOpportunity(){
        $app = App::i();
        
        if(!isset($this->data['@opportunity'])){
            $this->apiErrorResponse('parameter @opportunity is required');
        }
        
        if(!is_numeric($this->data['@opportunity'])){
            $this->apiErrorResponse('parameter @opportunity must be an integer');
        }

        $opportunity = $app->repo('Opportunity')->find($this->data['@opportunity']);
        
        if(!$opportunity){
            $this->apiErrorResponse('opportunity not found');
        }
        
        return $opportunity;
    }
    
    function getSelectFields(Entities\Opportunity $opportunity){
        $app = App::i();
        
        $fields = [];
        
        foreach($opportunity->registrationFieldConfigurations as $field){
            if($field->fieldType == 'select'){
                if(!isset($fields[$field->fieldName])){
                    $fields[$field->fieldName] = $field;
                }
            }
        }
        
        $app->applyHookBoundTo($this, 'controller(opportunity).getSelectFields', [$opportunity, &$fields]);
        
        return $fields;
    }
    
    function API_evaluationCommittee(){
        $this->requireAuthentication();
        
        $opportunity = $this->_getOpportunity();
        
        $opportunity->checkPermission('@control');
        
        $relations = $opportunity->getEvaluationCommittee();
        
        if(is_array($relations)){
            $result = array_map(function($e){
                $r = $e->simplify('id,status,createdAt');
                $r->owner = $e->owner->id;
                $r->agent = $e->agent->simplify('id,name,type,singleUrl,avatar');
                $r->agentUserId = $e->agent->userId;
                return $r;
            }, $relations);
        } else {
            $result = [];
        }
        
        $this->apiResponse($result);
    }
    
    function API_selectFields(){
        $app = App::i();
        
        $opportunity = $this->_getOpportunity();
        
        $fields = $this->getSelectFields($opportunity);
        
        $this->apiResponse($fields);
    }
    
    
    
    function API_findRegistrations() {
        $app = App::i();
        
        $app->registerFileGroup('registration', new \MapasCulturais\Definitions\FileGroup('zipArchive',[], '', true));
        
        $opportunity = $this->_getOpportunity();
        
        $data = $this->data;
        $data['opportunity'] = "EQ({$opportunity->id})";
        
        $_opportunity = $opportunity;
        $opportunity_tree = [];
        while($parent = $_opportunity->parent){
            $opportunity_tree[] = $parent;
            $_opportunity = $parent;
        }
        
        $opportunity_tree = array_reverse($opportunity_tree);
        
        $last_query_ids = null;
        
        $select_values = [];
        
        foreach($opportunity_tree as $current){
            $app->controller('registration')->registerRegistrationMetadata($current);
            $cdata = ['opportunity' => "EQ({$current->id})", '@select' => 'id,previousPhaseRegistrationId'];
            
            if($current->publishedRegistrations){
                $cdata['status'] = 'IN(10,8)';
            }
            
            foreach($current->registrationFieldConfigurations as $field){
                if($field->fieldType == 'select'){
                    $cdata['@select'] .= ",{$field->fieldName}";
                    
                    if(isset($data[$field->fieldName])){
                        $cdata[$field->fieldName] = $data[$field->fieldName];
                        unset($data[$field->fieldName]);
                    }
                }
            }
            if(!is_null($last_query_ids)){
                if($last_query_ids){
                    $cdata['previousPhaseRegistrationId'] = "IN($last_query_ids)";
                } else {
                    $cdata['id'] = "IN(-1)";
                }
            }
            $_disable_access_control = $current->publishedRegistrations && !$current->canUser('viewEvaluations');
            $q = new ApiQuery('MapasCulturais\Entities\Registration', $cdata, false, false, $_disable_access_control);
            
            $regs = $q->find();
            
            foreach($regs as $reg){
                
                if($reg['previousPhaseRegistrationId'] && isset($select_values[$reg['previousPhaseRegistrationId']])){
                    $select_values[$reg['id']] = $reg + $select_values[$reg['previousPhaseRegistrationId']];
                } else {
                    $select_values[$reg['id']] = $reg;
                }
            }
            
            $ids = array_map(function ($r) { return $r['id']; }, $regs);
            $last_query_ids = implode(',', $ids);
        }
        
        $app->controller('registration')->registerRegistrationMetadata($opportunity);
        
        unset($data['@opportunity']);
        
        if(!is_null($last_query_ids)){
            if($last_query_ids){
                $data['previousPhaseRegistrationId'] = "IN($last_query_ids)";
            } else {
                $data['id'] = "IN(-1)";
            }
        }
        
        if($select_values){
            $data['@select'] = isset($data['@select']) ? $data['@select'] . ',previousPhaseRegistrationId' : 'previousPhaseRegistrationId';
        }
        
        if($opportunity->publishedRegistrations && !$opportunity->canUser('viewEvaluations')){
            
            if(isset($data['status'])){
                $data['status'] = 'AND(IN(10,8),' . $data['status'] . ')';
            } else {
                $data['status'] = 'IN(10,8)';
            }
        }
        $query = new ApiQuery('MapasCulturais\Entities\Registration', $data, false, false, $opportunity->publishedRegistrations);
        
        $registrations = $query->find();
        $em = $opportunity->getEvaluationMethod();
        
        foreach($registrations as &$reg){
            $reg['number'] = 'on-' . $reg['id'];
            
            if(in_array('consolidatedResult', $query->selecting)){
                $reg['evaluationResultString'] = $em->valueToString($reg['consolidatedResult']);
            }
            
            if(isset($reg['previousPhaseRegistrationId']) && $reg['previousPhaseRegistrationId'] && isset($select_values[$reg['previousPhaseRegistrationId']])){
                $values = $select_values[$reg['previousPhaseRegistrationId']];
                foreach($reg as $key => $val){
                    if(is_null($val) && isset($values[$key])){
                        $reg[$key] = $values[$key];
                    }
                }
            }
        }
        $this->apiAddHeaderMetadata($this->data, $registrations, $query->getCountResult());
        $this->apiResponse($registrations);
    }
    
    function API_findEvaluations(){
        $this->requireAuthentication();
        
        $app = App::i();
        
        $_order = isset($this->data['@order']) ? strtolower($this->data['@order']) : 'valuer asc';
        
        if(preg_match('#(valuer|registration|evaluation|category)( +(asc|desc))?#i', $_order, $matches)){
            $order = $matches[1];
            $by = isset($matches[3]) ? strtolower($matches[3]) : 'asc';
        } else {
            $this->apiErrorResponse('invalid @order value');
        }
        
        $opportunity = $this->_getOpportunity();
        
            
        $committee_relation_query = new ApiQuery('MapasCulturais\Entities\EvaluationMethodConfigurationAgentRelation', [
            '@select' => 'id,agent',
            'owner' => "EQ({$opportunity->evaluationMethodConfiguration->id})",
        ]);
        $committee_relations = $committee_relation_query->find();
        $committee_ids = implode(',', array_map(function($e){return $e['agent']; }, $committee_relations));
        
        if($committee_ids){
            $vdata = [
                '@select' => 'id,name,user,singleUrl',
                'id' => "IN({$committee_ids})",
                '@permissions' => '@control'
            ];
            
            foreach($this->data as $k => $v){
                if(strtolower(substr($k, 0, 7)) === 'valuer:'){
                    $vdata[substr($k, 7)] = $v;
                }
            }
            
            $committee_query = new ApiQuery('MapasCulturais\Entities\Agent', $vdata);
            
            $committee = $committee_query->find();
        } else {
            $committee = [];
        }
        
        $valuer_by_user = [];
        
        foreach($committee as $valuer){
            $valuer_by_user[$valuer['user']] = $valuer;
        }
        
        $q = $app->em->createQuery("
            SELECT
                r.id AS registration, a.userId AS user, a.id AS valuer
            FROM
                MapasCulturais\Entities\RegistrationPermissionCache p
                JOIN p.owner r WITH r.opportunity = :opp
                JOIN p.user u
                INNER JOIN u.profile a WITH a.id IN (:aids)
            WHERE p.action = 'viewUserEvaluation'
        ");
        
        $params = [
            'opp' => $opportunity,
            'aids' => array_map(function ($el){ return $el['id']; }, $committee)
        ];
        
        $q->setParameters($params);
        
        $permissions = $q->getArrayResult();
        
        $registrations_by_valuer = [];
        
        foreach($permissions as $p){
            if(!isset($registrations_by_valuer[$p['valuer']])){
                $registrations_by_valuer[$p['valuer']] = [];
            }
            $registrations_by_valuer[$p['valuer']][$p['registration']] = true;
        }
        
        $registration_ids = array_map(function($r) { return $r['registration']; }, $permissions);
        if($registration_ids){
            $rdata = [
                '@select' => 'id,status,category,consolidatedResult,singleUrl',
                'id' => "IN(" . implode(',', $registration_ids).  ")"
            ];
            
            foreach($this->data as $k => $v){
                if(strtolower(substr($k, 0, 13)) === 'registration:'){
                    $rdata[substr($k, 13)] = $v;
                }
            }
            
            $registrations_query = new ApiQuery('MapasCulturais\Entities\Registration', $rdata);
            $registrations = $registrations_query->find();
            
            $edata = [
                '@select' => 'id,result,evaluationData,registration,user,status',
                'registration' => "IN(" . implode(',', $registration_ids).  ")"
            ];
            
            foreach($this->data as $k => $v){
                if(strtolower(substr($k, 0, 11)) === 'evaluation:'){
                    $edata[substr($k, 11)] = $v;
                }
            }
            
            $evaluations_query = new ApiQuery('MapasCulturais\Entities\RegistrationEvaluation', $edata);
            $evaluations = [];
            foreach($evaluations_query->find() as $e){
                $e['agent'] = $valuer_by_user[$e['user']];
                $e['resultString'] = $opportunity->getEvaluationMethod()->valueToString($e['result']);
                // $e['evaluationData'] = json_decode($e['evaluationData']);
                $evaluations[$e['user'] . ':' . $e['registration']] = $e;
            }
            
        } else {
            $registrations = [];
            $evaluations = [];
        }
        
        $_result = [];
        
        foreach($registrations as &$registration){
            $registration['number'] = 'on-' . $registration['id'];
            foreach($valuer_by_user as $user_id => $valuer){
                if(isset($registrations_by_valuer[$valuer['id']][$registration['id']])){
                    $_result[] = [
                        'registration' => $registration,
                        'evaluation' => isset($evaluations[$user_id . ':' . $registration['id']]) ? $evaluations[$user_id . ':' . $registration['id']] : null,
                        'valuer' => $valuer
                    ];
                }
            }
        }
        
        if(isset($this->data['@omitEmpty'])){
            $_result = array_filter($_result, function($e) { if($e['evaluation']) return $e; });
        }
        
        list($order, $order_by) = explode(' ', $_order);
        
        $order_by = $order_by == 'asc' ? 1 : -1;
        
        switch ($order) {
            case 'valuer':
                usort($_result, function($e1, $e2) use($order_by){
                    return strcasecmp($e1['valuer']['name'], $e2['valuer']['name']) * $order_by;
                });
                break;
                
            case 'category':
                usort($_result, function($e1, $e2) use($order_by){
                    return strcasecmp($e1['registration']['category'], $e2['registration']['category']) * $order_by;
                });
                break;
                
            case 'registration':
                usort($_result, function($e1, $e2) use($order_by){
                    if($e1['registration']['id'] > $e2['registration']['id']){
                        return $order_by;
                    } elseif($e1['registration']['id'] < $e2['registration']['id']){
                        return $order_by * -1;
                    } else {
                        return 0;
                    }
                });
                break;
                
            case 'evaluation':
                usort($_result, function($e1, $e2) use($order_by, $opportunity){
                    $em = $opportunity->getEvaluationMethod();
                    return $em->cmpValues($e1['evaluation']['result'], $e2['evaluation']['result']) * $order_by;
                });
                break;
        }
        
        // paginação
        if(isset($this->data['@limit'])){
            $limit = $this->data['@limit'];
            $page = isset($this->data['@page']) ? $this->data['@page'] : null;
            
            if (isset($this->data['@offset'])) {
                $offset = $this->data['@offset'];
            } else if ($page && $page > 1 && $limit) {
                $offset = $limit * ($page - 1);
            } else {
                $offset = 0;
            }
            
            $result = array_slice($_result, $offset, $limit);
            
        } else {
            $result = $_result;
        }
        $this->apiAddHeaderMetadata($this->data, $result, count($_result));
        $this->apiResponse($result);
    }

    function GET_exportFields() {
        $this->requireAuthentication();

        $app = App::i();

        if(!key_exists('id', $this->urlData)){
            $app->pass();
        }

        $fields = $user = $app->repo("RegistrationFieldConfiguration")->findBy(array('owner' => $this->urlData['id']));
        $files = $user = $app->repo("RegistrationFileConfiguration")->findBy(array('owner' => $this->urlData['id']));

        $opportunity =  $app->repo("Opportunity")->find($this->urlData['id']);

        if (!$opportunity->canUser('modify'))
            return false; //TODO return error message?

        $opportunityMeta = array(
            'registrationCategories',
            'useAgentRelationColetivo',
            'registrationLimitPerOwner',
            'registrationCategDescription',
            'registrationCategTitle',
            'useAgentRelationInstituicao',
            'introInscricoes',
            'registrationSeals',
            'registrationLimit'
        );

        $metadata = [];

        foreach ($opportunityMeta as $key) {
            $metadata[$key] = $opportunity->{$key};
        }

        $result = array(
            'files' => $files,
            'fields' => $fields,
            'meta' => $metadata
        );

        header('Content-disposition: attachment; filename=opportunity-'.$this->urlData['id'].'-fields.txt');
        header('Content-type: text/plain');
        echo json_encode($result);
    }

    function POST_importFields() {
        $this->requireAuthentication();

        $app = App::i();

        if(!key_exists('id', $this->urlData)){
            $app->pass();
        }

        $opportunity_id = $this->urlData['id'];

        if (isset($_FILES['fieldsFile']) && isset($_FILES['fieldsFile']['tmp_name']) && is_readable($_FILES['fieldsFile']['tmp_name'])) {

            $importFile = fopen($_FILES['fieldsFile']['tmp_name'], "r");
            $importSource = fread($importFile,filesize($_FILES['fieldsFile']['tmp_name']));
            $importSource = json_decode($importSource);

            $opportunity =  $app->repo("Opportunity")->find($opportunity_id);

            if (!$opportunity->canUser('modifyRegistrationFields'))
                return false; //TODO return error message?

            if (!is_null($importSource)) {


                // Fields
                foreach($importSource->fields as $field) {

                    $newField = new Entities\RegistrationFieldConfiguration;
                    $newField->owner = $opportunity;
                    $newField->title = $field->title;
                    $newField->description = $field->description;
                    $newField->maxSize = $field->maxSize;
                    $newField->fieldType = $field->fieldType;
                    $newField->required = $field->required;
                    $newField->categories = $field->categories;
                    $newField->fieldOptions = $field->fieldOptions;
                    $newField->displayOrder = $field->displayOrder;

                    $app->em->persist($newField);

                    $newField->save();

                }

                //Files (attachments)
                foreach($importSource->files as $file) {

                    $newFile = new Entities\RegistrationFileConfiguration;

                    $newFile->owner = $opportunity;
                    $newFile->title = $file->title;
                    $newFile->description = $file->description;
                    $newFile->required = $file->required;
                    $newFile->categories = $file->categories;
                    $newFile->displayOrder = $file->displayOrder;

                    $app->em->persist($newFile);

                    $newFile->save();

                    if (is_object($file->template)) {

                        $originFile = $app->repo("RegistrationFileConfigurationFile")->find($file->template->id);

                        if (is_object($originFile)) { // se nao achamos o arquivo, talvez este campo tenha sido apagado

                            $tmp_file = sys_get_temp_dir() . '/' . $file->template->name;

                            if (file_exists($originFile->path)) {
                                copy($originFile->path, $tmp_file);

                                $newTemplateFile = array(
                                    'name' => $file->template->name,
                                    'type' => $file->template->mimeType,
                                    'tmp_name' => $tmp_file,
                                    'error' => 0,
                                    'size' => filesize($tmp_file)
                                );

                                $newTemplate = new Entities\RegistrationFileConfigurationFile($newTemplateFile);

                                $newTemplate->owner = $newFile;
                                $newTemplate->description = $file->template->description;
                                $newTemplate->group = $file->template->group;

                                $app->em->persist($newTemplate);

                                $newTemplate->save();
                            }

                        }

                    }
                }

                // Metadata
                foreach($importSource->meta as $key => $value) {
                    $opportunity->$key = $value;
                }

                $opportunity->save(true);

                $app->em->flush();

            }

        }

        $app->redirect($opportunity->editUrl.'#tab=inscricoes');

    }

    function POST_saveFieldsOrder() {

        $this->requireAuthentication();

        $app = App::i();

        $owner = $this->requestedEntity;

        if(!$owner){
            $app->pass();
        }

        $owner->checkPermission('modify');

        $savedFields = array();

        $savedFields['fields'] = $owner->registrationFieldConfigurations;
        $savedFields['files'] = $owner->registrationFileConfigurations;

        if (!is_array($this->postData['fields'])){
            return false;
        }

        foreach ($this->postData['fields'] as $field) {

            $type = $field['fieldType'] == 'file' ? 'files' : 'fields';

            foreach ($savedFields[$type] as $savedField) {

                if ($field['id'] == $savedField->id) {

                    $savedField->displayOrder = (int) $field['displayOrder'];
                    $savedField->save(true);

                    break;

                }

            }

        }

    }
}
