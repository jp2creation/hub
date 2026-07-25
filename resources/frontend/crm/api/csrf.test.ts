import { describe, expect, it } from 'vitest';
import { isMutationMethod } from './csrf';

describe('HUB CSRF helpers', () => {
  it('identifies methods that need a CSRF token', () => {
    expect(isMutationMethod('POST')).toBe(true);
    expect(isMutationMethod('put')).toBe(true);
    expect(isMutationMethod('PATCH')).toBe(true);
    expect(isMutationMethod('DELETE')).toBe(true);
  });

  it('keeps read-only requests outside CSRF mutation handling', () => {
    expect(isMutationMethod('GET')).toBe(false);
    expect(isMutationMethod('HEAD')).toBe(false);
    expect(isMutationMethod('OPTIONS')).toBe(false);
  });
});
