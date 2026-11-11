import { transportContract } from '../../../../site/packages/api/testing/contract';
import { apiFetch } from './api';

transportContract(apiFetch);
