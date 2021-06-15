# wizard plugin

Plugin that allows you to easily implement and configure a wizard system of steps with forms and validations to [Winter CMS](https://wintercms.com)

![wizard_example](https://user-images.githubusercontent.com/491835/121741023-ec814a00-cacb-11eb-902e-1b01b5f2a0e3.png)

## Installation

```terminal
composer require zimudec/wn-wizard-plugin
```

### Usage example

Create a new page and add the "wizard" component. You must configure the url to admit an optional parameter "/:step?", Configure the wizard steps, functions for ajax and the views of each step. Here is a complete example of a page with the implementation and explanatory comments. It can be used as a starting point:

```
title = "Wizard Example"
url = "/wizard-example/:step?"
layout = "default"
is_hidden = 0

[wizard]
==
<?
function onInit()
{
  // Register and configure list of steps that the wizard component will use
  $this->wizard->steps = [

    // Each of the steps allows you to configure a list of form validations.
    // Once successfully validated, wizard will allow you to go to the next step.
    // The validations will be called from the related ajax request.
    ['step' => 'step1', 'name' => 'Step 1', 'forms' => [
      'onStep1' => [
        'validation' => ['field1' => 'required', 'field2' => 'required'],
        'extra_validation' => function($validator, $fields, $prevValidationsData) {
          // Extra validations
          $errors = $validator->errors();

          // You can optionally avoid performing the extra validations, 
          // if the previous validations are not fulfilled
          if(!$errors->any()){

            // Extra validations that are complex to perform in native array of laravel validations
            if($fields['field1'] != 'hello'){
              $errors->add('field1', 'The value of this field must be "hello"');
            }

            // Return data from here, allows you to use it in any subsequent step
            // For example: this allows you to validate by consulting a data in the database,
            // and reuse it in subsequent steps without having to consult it again
            // This data remains in session during all the steps, until it is revalidated
            return [
              'user' => ['id' => 1, 'names' => 'User', 'lastname' => 'my lastnames'],
            ];
          }
        }
      ]
    ]],
    // By default, when entering a page, the wizard does not validate the previous steps.
    // The "validatePrevSteps" field allows forcing the revalidation of all the previous steps.
    // This is useful when you need to update the data obtained from extra validations.
    ['step' => 'step2', 'name' => 'Step 2', 'validatePrevSteps' => true],
    ['step' => 'step3', 'name' => 'Step 3', 'forms' => [
      'onStep3' => [
        'validation' => ['select' => 'required'],
        'extra_validation' => function($validator, $fields, $prevValidationsData){
        }
      ]
    ]],
    ['step' => 'step4', 'name' => 'Step 4'],
  ];
  
}

function onEnd()
{
  // To include this script, add the tag {% put scripts%} {% endput%} after jquery
  // This script shows and hides errors received from the server. Use bootstrap 4.x classes
  $this->addJs('/plugins/zimudec/wizard/assets/js/wizard.js');

  $wizard = $this['wizard'];

  if($wizard['stepCurrent'] == 'step1'){
    // This only runs when opening the page. At this point, no validations are performed
    $this['example_data'] = 'This is the step 1';
  } 
  elseif( $wizard['stepCurrent'] == 'step2' ){
    // You can get data received from the extra validations, and transfer it to the view
    $this['user'] = $wizard['prevValidationsData']['user'];

    // You can get the values of the fields that have been filled in previous steps
    $this['field2'] = $wizard['fields']['field2'];
    $this['field3'] = $wizard['fields']['field3'];
  }
  elseif( $wizard['stepCurrent'] == 'step3' ){
    $this['selectData'] = [
      ['id' => 1, 'name' => 'Name 1'],
      ['id' => 2, 'name' => 'Name 2'],
      ['id' => 3, 'name' => 'Name 3'],
    ];
  }
}

function onStep1()
{
  // When submitting the form in step1, this function will be executed and the extra validations and validations will be performed
  $data = $this->wizard->formsValidate();

  // Here you can perform any procedure before redirecting.
  // This procedure will only be done 1 time when submitting the form.
  // ...

  // Here the rediction will be made to the next step
  return redirect($data['stepNext']);
}

function onStep2()
{
  $data = $this->wizard->formsValidate();
  return redirect($data['stepNext']);
}

function onStep3()
{
  // By default, only the fields of the current form are validated, and not the previous steps.
  // When adding true as a parameter to the function,
  // will force validation of all previous steps, in addition to the current one.
  // This is useful for committing all the wizard data before doing something important, 
  // like inserting data into the database.
  $data = $this->wizard->formsValidate(true);
  return redirect($data['stepNext']);
}
?>
==
<section>
  {% partial '@nav_header.htm' title=(this.page.title) %}

  {% if wizard.stepCurrent == 'step1' %}

  <div class="text-center">{{ example_data }}</div>
  <form class="mx-auto" style="max-width: 400px;" data-request="onStep1" data-request-validate>
    {{ form_token() }}

    {% partial '@input_text.htm' label="Field 1 *" name="field1" %}
    {% partial '@input_text.htm' label="Field 2 *" name="field2" %}
    {% partial '@input_text.htm' label="Field 3" name="field3" %}

    {% partial '@nav_buttons.htm' %}
  </form>

  {% elseif wizard.stepCurrent == 'step2' %}

  {% partial '@header.htm' text=('Welcome ' ~ user.names) subtitle='This step does not require entering fields' %}
  <form class="mx-auto" style="max-width: 400px;" data-request="onStep2" data-request-validate>
    {{ form_token() }}
    {% partial '@input_text.htm' label="Field 2" value=(field2) readonly=true %}
    {% partial '@input_text.htm' label="Field 3" value=(field3) readonly=true %}
    {% partial '@nav_buttons.htm' %}
  </form>

  {% elseif wizard.stepCurrent == 'step3' %}

  {% partial '@header.htm' subtitle='This is the last step before finish the wizard' %}
  <form class="mx-auto" style="max-width: 400px;" data-request="onStep3" data-request-validate>
    {{ form_token() }}

    {% partial '@input_select.htm' label="Select *" name="select" items=(selectData)  %}
    {% partial '@nav_buttons.htm' %}
  </form>

  {% elseif wizard.stepCurrent == 'step4' %}

  <div class="mt-3 text-center">Wizard Finished. The wizard session was deleted. If you reload the page, it will go back to step1.</div>
  <hr >
  {% partial '@nav_buttons.htm' %}

  {% endif %}
</section>
```