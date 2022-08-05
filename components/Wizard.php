<?php namespace Zimudec\Wizard\Components;

use Cms\Classes\ComponentBase;
use Cms\Classes\Page;
use Winter\Storm\Support\Arr;
use Validator;
use ValidationException;

class Wizard extends ComponentBase
{
    public $steps = [];
    public $stepPos = 0;
    public $stepNext = null;

    public function componentDetails()
    {
        return [
            'name'        => 'Wizard Component',
            'description' => 'This component assigned to a page, allows to use the wizard system of forms by steps and validations'
        ];
    }

    public function defineProperties()
    {
        return [];
    }

    public function stepNextGet($key)
    {
        if (isset($this->steps[$key + 1]) && $this->steps[$key + 1]['step']) {
            return Page::url($this->page->fileName, ['step' => $this->steps[$key + 1]['step']]);
        }

        return null;
    }

    public function formValidate($requestData, $validationFields, $validationMessages, $validationExtras)
    {
        $resp = [];

        $validator = Validator::make($requestData, $validationFields, $validationMessages);

        foreach ($validationExtras as $validationExtra) {
            $validator->after(function ($validator) use ($validationExtra, $requestData, &$resp) {
                $result = $validationExtra($validator, $requestData, $resp);

                if (is_array($result)) {
                    $resp = array_merge($resp, $result);
                }
            });
        }

        if ($validator->fails()) {
            return false;
        }

        if (count($resp) > 0) {
            return $resp;
        } else {
            return [];
        }
    }

    public function sendToStep($step)
    {
        if (preg_match('/\:step\?/', $this->page->url)) {
            if (isset($this->steps[$step]['step'])) {
                redirect(Page::url($this->page->fileName, ['step' => $this->steps[$step]['step']]))->send();
                exit();
            } else {
                dump('You must correctly define the wizard component configuration arrangement');
            }
        } else {
            dump('You must add "/:step?" at the end the url of your page');
        }
    }

    public function sendFirstStep()
    {
        $this->sendToStep(0);
    }

    public function formsValidate($validatePrevSteps = false)
    {
        if (!$this->param('step')) {
            exit();
        }

        if (request()->ajax()) {
            // Validate if it is allowed to enter the step, according to the data of the previous steps (session)
            $wizardSession = session()->get('wizard_steps', []);
            $resp = ($validatePrevSteps
                ? $this->stepValidate()
                : (isset($wizardSession['validations']) ? $wizardSession['validations'] : [])
            );

            $stepPos = 0;
            foreach ($this->steps as $key => $step) {
                if ($step['step'] == $this->param('step')) {
                    $stepPos = $key;
                    break;
                }
            }

            $methodFull = explode('::', request()->header('x-winter-request-handler'));
            $method = isset($methodFull[1]) ? $methodFull[1] : $methodFull[0];

            if (
                isset($this->steps[$stepPos]['forms'][$method]['validation']) ||
                isset($this->steps[$stepPos]['forms'][$method]['extra_validation'])
            ) {
                $formStep = $this->steps[$stepPos]['forms'][$method];

                if (!isset($formStep['validation'])) {
                    $formStep['validation'] = [];
                }

                $requestData = request()->all();

                $validator = Validator::make(
                    $requestData,
                    $formStep['validation'],
                    array_get($formStep, 'validation_messages', [])
                );

                if (isset($formStep['extra_validation'])) {
                    $validator->after(function ($validator) use ($formStep, &$resp, $requestData) {
                        $result = $formStep['extra_validation'](
                            $validator,
                            array_merge($requestData, session()->get('wizard_steps', [])),
                            $resp
                        );

                        if (is_array($result)) {
                            $resp = array_merge($resp, $result);
                        }
                    });
                }

                if ($validator->fails()) {
                    throw new ValidationException($validator);
                    exit();
                }

                // Save data in session
                $session = array_merge(session()->get('wizard_steps', []), $requestData);
                if (!isset($session['validations'])) {
                    $session['validations'] = [];
                }
                $session['validations'] = array_merge($session['validations'], $resp);

                session(['wizard_steps' => $session]);
            }

            // Save last validated step
            session([
                'wizard_steps' => array_merge(session()->get('wizard_steps', []), ['stepCurrent' => ($stepPos + 1)])
            ]);

            // Return next step to redirect
            $resp['stepNext'] = $this->stepNextGet($stepPos);

            return $resp;
        }
    }

    public function stepValidate()
    {
        $validationFields   = [];
        $validationMessages = [];
        $validationExtras   = [];

        // Validate if the data from the previous steps is correct
        foreach ($this->steps as $stepPos => $step) {
            // Validate until the previous step
            if ($step['step'] == $this->param('step')) {
                break;
            }

            // Build list of fields and extras to validate
            if (isset($this->steps[$stepPos]['forms'])) {
                foreach ($this->steps[$stepPos]['forms'] as $formStep) {
                    if (isset($formStep['validation'])) {
                        $validationFields = array_merge($validationFields, $formStep['validation']);
                    }
                    if (isset($formStep['validation_messages'])) {
                        $validationMessages = array_merge($validationMessages, $formStep['validation_messages']);
                    }
                    if (isset($formStep['extra_validation'])) {
                        $validationExtras[] = $formStep['extra_validation'];
                    }
                }
            }
        }

        $dataExtra = $this->formValidate(
            session()->get('wizard_steps', []),
            $validationFields,
            $validationMessages,
            $validationExtras
        );

        if ($dataExtra === false) {
            // Fails. Redirect to step 1
            $this->sendFirstStep();
        }

        return $dataExtra;
    }

    public function stepSessionClear($currentStepPos)
    {
        $dataToClear = [];

        // Clean the session of data from steps after the current one
        foreach ($this->steps as $key => $step) {
            if ($key < $currentStepPos) {
                continue;
            }

            // Build list of fields to clean
            if (isset($this->steps[$key]['forms'])) {
                foreach ($this->steps[$key]['forms'] as $formStep) {
                    if (isset($formStep['validation'])) {
                        $dataToClear = array_merge($dataToClear, array_keys($formStep['validation']));
                    }
                }
            }
        }

        $newSession = array_merge(
            Arr::except(session()->get('wizard_steps', []), $dataToClear),
            ['stepCurrent' => $currentStepPos]
        );
        session(['wizard_steps' => $newSession]);
    }

    public function onRun()
    {
        // If a step is not defined in the url, redirect to step 1
        if (!$this->param('step')) {
            $this->sendFirstStep();
            return;
        }

        // Build url of next and previous buttons
        $dataExtra = [];
        $wizardData = [];
        $wizardData['stepCurrent'] = $this->param('step');
        $wizardData['stepNext'] = false;
        $wizardData['stepPrev'] = false;

        foreach ($this->steps as $key => $step) {
            if ($step['step'] == $wizardData['stepCurrent']) {
                // Validate if it is allowed to enter the step, according to the data of the previous steps
                $wizardSession = session()->get('wizard_steps', []);
                // dd($wizardSession);
                $stepValid = 0;
                if (count($wizardSession) > 0 && isset($wizardSession['stepCurrent'])) {
                    $stepValid = $wizardSession['stepCurrent'];
                }

                if ($key > $stepValid) {
                    $this->sendToStep($stepValid);
                }

                // Validate forms from previous steps
                $validatePrevSteps = false;
                if (isset($step['validatePrevSteps'])) {
                    $validatePrevSteps = $step['validatePrevSteps'];
                }

                $dataExtra = $validatePrevSteps
                    ? $this->stepValidate()
                    : (isset($wizardSession['validations']) ? $wizardSession['validations'] : []);

                $this->stepPos = $key;

                $wizardData['stepNext'] = $this->stepNextGet($key);

                if (isset($this->steps[$key - 1]) && $this->steps[$key - 1]['step']) {
                    $wizardData['stepPrev'] = Page::url(
                        $this->page->fileName,
                        ['step' => $this->steps[$key - 1]['step']]
                    );
                }
                break;
            }

        }

        $wizardData['steps'] = $this->steps;
        $wizardData['fields'] = session()->get('wizard_steps');
        $wizardData['prevValidationsData'] = $dataExtra;

        $this->page['wizard'] = $wizardData;

        if (!$wizardData['stepNext']) {
            // If I am in the last step, clear the whole wizard session, to start again
            session(['wizard_steps' => []]);
        } else {
            // If not, clean session fields from steps after the current one
            $this->stepSessionClear($this->stepPos);
        }
    }
}
