export default {
    title: 'Overtime',
    lead: 'All your overtime, with reasons, work reports, and Management decisions. A decision only sets whether the overtime minutes count. It never stops you from working.',
    timezone_note: 'Times are Jakarta time (WIB).',

    filters: {
        label: 'Filter overtime',
        status: 'Status',
        status_all: 'All statuses',
        month: 'Month',
        month_all: 'All months',
        clear: 'Show all overtime',
    },

    count: ':from to :to of :total overtime entries',

    states: {
        loading: 'Loading overtime...',
        error_title: 'The overtime list could not load.',
        error_body: 'Check your internet connection, then try again. Times already recorded are not lost.',
        retry: 'Try again',
        empty_title: 'No overtime yet.',
        empty_body:
            'On a workday, overtime starts after :limit, when you choose to keep working instead of clocking out. On a day that is not a workday, all hours count as overtime from clock-in. Both work from the My day page or the Owlorix HR desktop app, and the overtime then shows up here.',
        empty_body_desktop:
            'Overtime starts in the Owlorix HR desktop app, because clocking in from a browser is turned off. On a workday, after :limit the app asks whether you clock out or keep working. On a day that is not a workday, all hours count as overtime from clock-in. Your overtime then shows up here.',
        filtered_title: 'No overtime matches.',
        filtered_body: 'There is no overtime with this status and month. Pick another status or month.',
    },

    pagination: {
        label: 'Overtime list pages',
        previous: 'Previous',
        next: 'Next',
        page: 'Page :current of :last',
    },

    status: {
        pending: 'Waiting for approval',
        approved: 'Approved',
        rejected: 'Rejected',
    },

    item: {
        range: ':start to :end',
        running_range: 'Since :start, still running',
        late_claim: 'Late claim',
        reason: 'Reason',
        no_reason: 'No reason recorded.',
        report: 'Work report',
        report_due_title: 'Work report not written yet',
        report_due: 'Write it in the Owlorix HR desktop app: it asks again the next time you sign in. Management can decide once the report is there.',
        report_due_web: 'Write it on the My day page or in the Owlorix HR desktop app. Management can decide once the report is there.',
        open_my_day: 'Write the report on My day',
        running: 'Overtime is still running. You are asked for the work report when you clock out.',
        zero_minutes: 'This shift no longer has overtime minutes after a recalculation, for example after a correction or a calendar change. Its decision history is kept.',
        rejected_by: 'Rejected by :name, :time',
        rejected_at: 'Rejected, :time',
        rejected_no_note: 'No note.',
        decisions: 'Decision history',
        earlier_decisions: 'Earlier decisions',
        no_decision: 'Not decided by Management yet.',
        waiting_report: 'Not decided yet. Management can decide once the work report is written.',
        decision_by: ':decision by :name',
        decision: { approved: 'Approved', rejected: 'Rejected' },
        reset: 'Waiting for approval again because the overtime minutes changed after the decision.',
        open_history: 'See the shift in History',
    },

    claims: {
        heading: 'Overtime claims you can still file',
        prompt: 'The shift on :date was closed automatically at :time because the regular time reminder was not answered within :answer.',
        presence_check: 'Overtime on :date stopped automatically at :time because Still working overtime? was not answered within :answer.',
        deadline: 'Claim deadline: :deadline',
        latest_end: 'The latest end time you can claim is :time, the last recorded activity on that shift.',
        where: 'If you kept working after that, file a claim in the Owlorix HR desktop app with a reason and a work report.',
        where_web: 'If you kept working after that, file a claim with a reason and a work report on the My day page or in the Owlorix HR desktop app.',
        open_my_day: 'File the claim on My day',
    },
};
