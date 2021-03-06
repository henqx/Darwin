# Project Darwin
Darwin is a utility class for incrementally (i.e. over time) determining the optimal combinations of a set, based on a value-returning function that is used as the basis of comparison (ranking).
Note that in the current state, the project is not generalisable nor easily usable in external projects, but rather functions as an illustrative example of how such an implementation could be achieved.

## Overview
The main point of entry is index.php. An overview of the files is provided below:
- index.php: imports the relevant classes (including darwin, utilities and loggers) as well as instantiates a Darwin run.
- darwin.php: contains the entire Darwin class
- utilities.php: contains utility functions, including a timer and general math functions (e.g. wilson interval calculations)
- config.php: contains a wrapper function for the built-in PHP MySQL query, which adds some project-specific functionality.

## Current usage
The current implementation applies Darwin on a combination of creative elements (sender name, subject and body) and template blocks that are used to build marketing emails. 
The basis of optimization is the engagement level of the receivers/subscribers of the marketing emails, currently approximated as the average open rate (amount of unique opens over sends) for that combination/aggregate.