import * as React from 'react';
import * as JsxRuntime from 'react/jsx-runtime';
import * as ReactDom from 'react-dom';
import * as ReactRouter from 'react-router';
import * as ReactQuery from '@tanstack/react-query';
import type { QueryClient } from '@tanstack/react-query';
import * as I18next from 'i18next';
import * as ReactI18next from 'react-i18next';
import { apiFetch } from '../api/client';
import { ApiProblem, fieldErrors, isStepUpCancelled, problemMessage } from '../api/problems';
import { Button } from '../components/ui/Button';
import { CardList } from '../components/ui/CardList';
import { Chip } from '../components/ui/Chip';
import { ConfirmDialog } from '../components/ui/ConfirmDialog';
import { DataTable } from '../components/ui/DataTable';
import { Dialog } from '../components/ui/Dialog';
import { Drawer } from '../components/ui/Drawer';
import { EmptyState } from '../components/ui/EmptyState';
import { Field } from '../components/ui/Field';
import { Pager } from '../components/ui/Pager';
import { Select } from '../components/ui/Select';
import { StatCard } from '../components/ui/StatCard';
import { Switch } from '../components/ui/Switch';
import { TextInput } from '../components/ui/TextInput';
import { useToast } from '../components/ui/Toast';
import { useMe } from '../features/auth/useMe';
import { PageSpinner } from '../layout/PageSpinner';
import { formatBytes, formatBytesOf, formatDate, formatDay, formatRelative } from '../lib/format';
import { registerModule } from './registry';

export type { ModuleDefinition } from './registry';

/**
 * What a module bundle may use from the core, on `globalThis.__emailsquire`. Versioned with the server's
 * `CoreApi::VERSION`: removing or changing anything here is a major bump, adding is a minor one.
 */
export type Host = {
    apiVersion: '1.0.0';
    // The module SDK's Vite plugin turns a module's imports of these packages into reads from here, so
    // there is one React (and one router, query cache, i18next) on the page.
    libs: {
        react: typeof React;
        'react/jsx-runtime': typeof JsxRuntime;
        'react-dom': typeof ReactDom;
        'react-router': typeof ReactRouter;
        '@tanstack/react-query': typeof ReactQuery;
        i18next: typeof I18next;
        'react-i18next': typeof ReactI18next;
    };
    api: {
        apiFetch: typeof apiFetch;
        ApiProblem: typeof ApiProblem;
        problemMessage: typeof problemMessage;
        fieldErrors: typeof fieldErrors;
        isStepUpCancelled: typeof isStepUpCancelled;
    };
    hooks: { useMe: typeof useMe };
    format: {
        formatBytes: typeof formatBytes;
        formatBytesOf: typeof formatBytesOf;
        formatRelative: typeof formatRelative;
        formatDay: typeof formatDay;
        formatDate: typeof formatDate;
    };
    ui: {
        Button: typeof Button;
        CardList: typeof CardList;
        Chip: typeof Chip;
        ConfirmDialog: typeof ConfirmDialog;
        DataTable: typeof DataTable;
        Dialog: typeof Dialog;
        Drawer: typeof Drawer;
        EmptyState: typeof EmptyState;
        Field: typeof Field;
        PageSpinner: typeof PageSpinner;
        Select: typeof Select;
        Switch: typeof Switch;
        TextInput: typeof TextInput;
        Pager: typeof Pager;
        StatCard: typeof StatCard;
        useToast: typeof useToast;
    };
    /** The console's query cache, for work outside a component (invalidating after a module's own event). */
    queryClient: QueryClient;
    registerModule: typeof registerModule;
};

/** Sets the frozen host object on `globalThis.__emailsquire`; `main.tsx` calls it before loading modules. */
export function installHost(queryClient: QueryClient): Host {
    // Module namespace objects are read-only already; the groups holding them are frozen here.
    const host: Host = Object.freeze({
        apiVersion: '1.0.0',
        libs: Object.freeze({
            react: React,
            'react/jsx-runtime': JsxRuntime,
            'react-dom': ReactDom,
            'react-router': ReactRouter,
            '@tanstack/react-query': ReactQuery,
            i18next: I18next,
            'react-i18next': ReactI18next,
        }),
        api: Object.freeze({ apiFetch, ApiProblem, problemMessage, fieldErrors, isStepUpCancelled }),
        hooks: Object.freeze({ useMe }),
        format: Object.freeze({ formatBytes, formatBytesOf, formatRelative, formatDay, formatDate }),
        ui: Object.freeze({ Button, CardList, Chip, ConfirmDialog, DataTable, Dialog, Drawer, EmptyState, Field, PageSpinner, Select, Switch, TextInput, Pager, StatCard, useToast }),
        queryClient,
        registerModule,
    });
    (globalThis as { __emailsquire?: Host }).__emailsquire = host;
    return host;
}
