<?php

namespace App;

enum KnowledgeImprovementTarget: string
{
    case Skill = 'skill';
    case Rule = 'rule';
    case RegressionTest = 'regression_test';
    case Documentation = 'documentation';
}
