## About

This profile is a support for Drupal training sessions. It works alone but can be completed with three submodules: 

* training_content : for creating content,
* training_module: for implementation examples,
* training_correction: for the corrections of the exercises,
* training_theme: a theme inherited from Bartik.

## Get the tools 

First, get the `training_profil` and put it in your Drupal profiles folder. 

```
git clone git@gitlab.makina-corpus.net:formation/back-end/drupal-profile-training-profile.git training_profile
```

Depending on the training to be given, you can complete this profile with the appropriate submodules.


##### Drupal back

```
git submodule init modules/training_content
git submodule update --remote
```

##### Drupal dev

```
git submodule init modules/training_content
git submodule init modules/training_module
git submodule init modules/training_correction
git submodule update --remote
```

##### Drupal front 

```
git submodule init modules/training_content
git submodule init themes/training_theme
git submodule update --remote
```
